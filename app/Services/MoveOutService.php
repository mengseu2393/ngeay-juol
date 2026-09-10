<?php

namespace App\Services;

use App\Enums\BillingType;
use App\Enums\DepositDeductionCategory;
use App\Enums\DepositSettlementStatus;
use App\Enums\FirstMonthBillingMode;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Filament\Pages\MonthlyBilling;
use App\Models\DepositDeduction;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\UtilityUsage;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The exit counterpart of {@see CompleteMoveInAction}.
 *
 * Move-in was fully modelled — requirements, a payment gate, a readiness check,
 * an activation action. Move-out was "edit the tenancy and pick Vacated": no
 * final meter reading, no closing invoice, and — the asymmetry that mattered —
 * no exit path at all for the security deposit collected on every tenancy.
 *
 * One call, one transaction, five steps:
 *   1. stamp the move-out date and actor;
 *   2. capture final meter readings (through {@see MeterReadingResolver}, so a
 *      swapped meter and an un-metered room behave exactly as they do in the
 *      monthly run);
 *   3. build the closing invoice via {@see InvoiceBuilderService}, with the
 *      partial final period priced by {@see ProratingService};
 *   4. settle the deposit — itemised deductions with reasons, resulting refund,
 *      all snapshotted at one exchange rate ({@see DepositSettlement});
 *   5. flip the tenancy to Vacated and hand the room back.
 *
 * Nothing here reimplements proration or invoice building; both are driven the
 * same way {@see MonthlyBilling} drives them.
 */
class MoveOutService
{
    /**
     * @param  array{
     *     move_out_date?: string|Carbon,
     *     move_out_reason?: string|null,
     *     final_readings?: array<int|string, mixed>,
     *     create_final_invoice?: bool,
     *     prorate_final_rent?: bool,
     *     deductions?: array<int, array{category?: int|DepositDeductionCategory, reason?: string, amount?: float|string, currency?: string}>,
     *     refund_reference?: string|null,
     *     refund_paid_at?: string|null,
     *     notes?: string|null,
     * }  $data
     * @return array{rental: Rental, invoice: ?Invoice, settlement: DepositSettlement, usages: array<int, UtilityUsage>}
     */
    public function execute(Rental $rental, array $data = [], ?int $actorId = null): array
    {
        return DB::transaction(function () use ($rental, $data, $actorId) {
            // withoutGlobalScopes() is required to read landlord_id/property_id off
            // the parent row; ownership is therefore asserted explicitly.
            $rental = Rental::withoutGlobalScopes()
                ->with(['unit', 'property', 'tenant'])
                ->whereKey($rental->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            LandlordOwnershipGuard::assertOwned($rental);

            $moveOutDate = $this->resolveMoveOutDate($rental, $data);
            $actorId ??= auth()->id();

            $setting = PropertySetting::where('property_id', $rental->property_id)->first();

            // ---- 2. Final meter readings -------------------------------------
            $usages = $this->recordFinalReadings($rental, $moveOutDate, $data['final_readings'] ?? [], $actorId);

            // ---- 3. Closing invoice ------------------------------------------
            $invoice = ($data['create_final_invoice'] ?? true)
                ? $this->buildFinalInvoice($rental, $setting, $moveOutDate, $usages, (bool) ($data['prorate_final_rent'] ?? true))
                : null;

            // ---- 4. Deposit settlement ---------------------------------------
            $settlement = $this->settleDeposit($rental, $setting, $invoice, $data, $actorId);

            // ---- 1/5. Stamp the move-out and release the room ----------------
            $rental->forceFill([
                'status' => RentalStatus::Vacated,
                'move_out_date' => $moveOutDate->toDateString(),
                'moved_out_at' => now(),
                'moved_out_by_id' => $actorId,
                'move_out_reason' => $data['move_out_reason'] ?? null,
                'end_date' => ($rental->end_date && Carbon::parse($rental->end_date)->lt($moveOutDate))
                    ? $rental->end_date
                    : $moveOutDate->toDateString(),
                // The tenancy is closed; nothing further is scheduled for it.
                'next_invoice_date' => null,
            ])->save();

            $rental->releaseUnit();

            return [
                'rental' => $rental->refresh(),
                'invoice' => $invoice,
                'settlement' => $settlement->refresh(),
                'usages' => $usages,
            ];
        });
    }

    // ---------------------------------------------------------------------
    // Guards
    // ---------------------------------------------------------------------

    /**
     * Domain rules that can be decided before anything is written. The
     * deductions-vs-deposit rule deliberately is NOT one of them — it is priced
     * in the settlement's currency at the settlement's rate, and that rate is
     * only known once the closing invoice has snapshotted it. See
     * {@see settleDeposit()}.
     */
    protected function resolveMoveOutDate(Rental $rental, array $data): Carbon
    {
        if ($rental->hasMovedOut()) {
            throw new \DomainException('This tenancy has already been moved out.');
        }

        $moveOutDate = isset($data['move_out_date'])
            ? Carbon::parse($data['move_out_date'])->startOfDay()
            : Carbon::now()->startOfDay();

        if ($rental->start_date && $moveOutDate->lt(Carbon::parse($rental->start_date)->startOfDay())) {
            throw ValidationException::withMessages([
                'move_out_date' => __('The move-out date cannot be before the tenancy start date.'),
            ]);
        }

        return $moveOutDate;
    }

    // ---------------------------------------------------------------------
    // Final readings
    // ---------------------------------------------------------------------

    /**
     * One closing reading per metered utility, plus a zero-usage row for each
     * flat-rate utility so the closing invoice bills it the way a normal cycle
     * would. Baselines come from {@see MeterReadingResolver::baselineFor()} —
     * the same path the room pages use — so a meter's multiplier, its digit
     * rollover and the legacy un-metered fallback all still apply.
     *
     * @param  array<int|string, mixed>  $readings  property_utility_id => closing index
     * @return array<int, UtilityUsage>
     */
    protected function recordFinalReadings(Rental $rental, Carbon $moveOutDate, array $readings, ?int $actorId): array
    {
        if (! $rental->unit_id) {
            return [];
        }

        $utilities = PropertyUtility::withoutGlobalScopes()
            ->where('property_id', $rental->property_id)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $resolver = app(MeterReadingResolver::class);
        $date = $moveOutDate->toDateString();
        $usages = [];

        foreach ($utilities as $utility) {
            $raw = $readings[$utility->getKey()] ?? $readings[(string) $utility->getKey()] ?? null;
            $isFlat = $utility->billing_type === BillingType::Flat;

            if ($raw === null || $raw === '') {
                if (! $isFlat) {
                    continue; // no closing index supplied → nothing honest to bill
                }
                $new = null;
                $old = null;
                $amountUsed = 0.0;
            } else {
                $new = (float) $raw;
                $baseline = $resolver->baselineFor((int) $rental->unit_id, (int) $utility->getKey(), $date, $new);
                $old = $baseline['old'];
                $amountUsed = $baseline['amount'];
            }

            $usages[] = UtilityUsage::updateOrCreate(
                [
                    'unit_id' => $rental->unit_id,
                    'rental_id' => $rental->getKey(),
                    'property_utility_id' => $utility->getKey(),
                    'reading_date' => $date,
                ],
                [
                    'landlord_id' => $rental->landlord_id,
                    'recorded_by_id' => $actorId ?: $rental->landlord_id,
                    'reading_type' => ReadingType::Actual,
                    'old_reading' => $old,
                    'new_reading' => $new,
                    'amount_used' => $amountUsed,
                    'is_waived' => false,
                ],
            );
        }

        return $usages;
    }

    // ---------------------------------------------------------------------
    // Closing invoice
    // ---------------------------------------------------------------------

    /**
     * The closing invoice covers everything not yet billed, up to and including
     * the move-out day.
     *
     * `next_invoice_date` is authoritative for where that period starts (it is
     * what the monthly run advances); a tenancy that has never been billed falls
     * back to the later of its start date and the move-out month's first day.
     *
     * Rent for that partial period is priced by {@see ProratingService} — daily
     * when the caller asked to prorate, otherwise a full month. The property's
     * own `first_month_billing_mode` is NOT reused here: it is a policy about
     * arriving, and half-month-on-arrival does not imply half-month-on-exit.
     */
    protected function buildFinalInvoice(
        Rental $rental,
        ?PropertySetting $setting,
        Carbon $moveOutDate,
        array $usages,
        bool $prorate,
    ): ?Invoice {
        $periodEnd = $moveOutDate->copy();
        $periodStart = $rental->next_invoice_date
            ? Carbon::parse($rental->next_invoice_date)->startOfDay()
            : Carbon::parse($rental->start_date)->startOfDay()->max($moveOutDate->copy()->startOfMonth());

        if ($rental->start_date && $periodStart->lt(Carbon::parse($rental->start_date))) {
            $periodStart = Carbon::parse($rental->start_date)->startOfDay();
        }

        // Rent is already paid through the move-out date — only leftover
        // utilities (if any) still need a bill.
        $includeRent = $periodStart->lte($periodEnd);
        if (! $includeRent) {
            $periodStart = $periodEnd->copy();
        }

        $billableUsages = array_filter(
            $usages,
            fn (UtilityUsage $usage) => (float) $usage->amount_used > 0
                || $usage->propertyUtility?->billing_type === BillingType::Flat,
        );

        if (! $includeRent && $billableUsages === []) {
            return null; // nothing left to charge
        }

        $alreadyBilled = Invoice::withoutGlobalScopes()
            ->where('rental_id', $rental->getKey())
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->exists();

        if ($alreadyBilled) {
            return null;
        }

        $params = [
            'rental' => $rental,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issue_date' => $moveOutDate,
            'include_rent' => $includeRent,
            'is_first_invoice' => false,
            'usages' => array_values($billableUsages),
            'notes' => __('Final invoice — move-out'),
        ];

        if ($includeRent) {
            $rentCurrency = Money::normalize($rental->monthly_rent_currency ?: 'USD');
            $fullRent = (float) $rental->monthly_rent;
            $finalRent = ProratingService::compute(
                new PropertySetting([
                    'first_month_billing_mode' => $prorate
                        ? FirstMonthBillingMode::Prorated
                        : FirstMonthBillingMode::FullMonth,
                ]),
                $fullRent,
                $periodStart,
                $periodEnd,
                $rentCurrency,
            );

            // Only flag the line as adjusted when it actually differs from the
            // agreed monthly rent — a full closing month is not an adjustment.
            if (abs($finalRent - round($fullRent, Money::decimals($rentCurrency))) >= 0.005) {
                $params['rent_override'] = [
                    'state' => 'custom',
                    'amount' => $finalRent,
                    'currency' => $rentCurrency,
                    'reason' => __('Final rent period').': '
                        .$periodStart->format('d M Y').' - '.$periodEnd->format('d M Y'),
                ];
            }
        }

        if ($setting?->invoice_due_days) {
            $params['due_date'] = $moveOutDate->copy()->addDays((int) $setting->invoice_due_days);
        }

        return app(InvoiceBuilderService::class)->create($params);
    }

    // ---------------------------------------------------------------------
    // Deposit settlement
    // ---------------------------------------------------------------------

    /**
     * Deposit held, itemised deductions, refund owed — one auditable record.
     *
     * Every figure is stored with USD/KHR twins converted at one snapshotted
     * `exchange_rate`, so re-reading the settlement years later reproduces the
     * numbers the tenant was actually handed. The rate is taken from a *saved*
     * source only (the closing invoice's snapshot, then the property's saved
     * rate) — never a live fetch — with the same 4000 last-resort constant
     * InvoiceBuilderService uses for its line conversions.
     *
     * The settlement's own totals are never written here: creating the child
     * deductions triggers {@see DepositSettlement::recalculateTotals()} from
     * {@see DepositDeduction::booted()}.
     */
    protected function settleDeposit(
        Rental $rental,
        ?PropertySetting $setting,
        ?Invoice $invoice,
        array $data,
        ?int $actorId,
    ): DepositSettlement {
        $currency = Money::normalize($rental->security_deposit_currency ?: $rental->monthly_rent_currency);
        $deposit = round((float) ($rental->security_deposit ?: 0), Money::decimals($currency));

        $rate = (float) ($invoice?->usd_khr_rate ?: $setting?->usd_khr_exchange_rate ?: 0);
        if ($rate <= 0) {
            $rate = 4000.0;
        }

        $rows = $this->normalizeDeductions($data['deductions'] ?? [], $currency, $rate);

        $totalInDepositCurrency = round(
            array_sum(array_map(
                fn (array $row) => Money::convert($row['amount'], $row['currency'], $currency, $rate),
                $rows,
            )),
            Money::decimals($currency),
        );

        if ($totalInDepositCurrency > $deposit + 0.005) {
            throw ValidationException::withMessages([
                'deductions' => __('Deductions (:total) cannot exceed the security deposit held (:deposit).', [
                    'total' => Money::format($totalInDepositCurrency, $currency),
                    'deposit' => Money::format($deposit, $currency),
                ]),
            ]);
        }

        $refundPaidAt = $data['refund_paid_at'] ?? null;

        $settlement = DepositSettlement::create([
            'rental_id' => $rental->getKey(),
            'landlord_id' => $rental->landlord_id,
            'final_invoice_id' => $invoice?->getKey(),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'deposit_amount' => $deposit,
            'deposit_amount_usd' => Money::convert($deposit, $currency, 'USD', $rate),
            'deposit_amount_khr' => Money::convert($deposit, $currency, 'KHR', $rate),
            'status' => $refundPaidAt ? DepositSettlementStatus::Refunded : DepositSettlementStatus::Settled,
            'settled_at' => now(),
            'settled_by_id' => $actorId,
            'refund_reference' => $data['refund_reference'] ?? null,
            'refund_paid_at' => $refundPaidAt,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($rows as $row) {
            DepositDeduction::create([
                'deposit_settlement_id' => $settlement->getKey(),
                'landlord_id' => $rental->landlord_id,
                'category' => $row['category'],
                'reason' => $row['reason'],
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'amount_usd' => Money::convert($row['amount'], $row['currency'], 'USD', $rate),
                'amount_khr' => Money::convert($row['amount'], $row['currency'], 'KHR', $rate),
                'exchange_rate' => $rate,
                'created_by_id' => $actorId,
            ]);
        }

        // No deductions means no child ever fired the recompute hook, so the
        // refund still has to be derived from the deposit exactly once.
        if ($rows === []) {
            $settlement->recalculateTotals();
        }

        return $settlement;
    }

    /**
     * Reject shapeless deduction rows before any of them is written; blank rows
     * (an untouched repeater entry) are dropped rather than rejected.
     *
     * @param  array<int, array<string, mixed>>  $deductions
     * @return array<int, array{category: DepositDeductionCategory, reason: string, amount: float, currency: string}>
     */
    protected function normalizeDeductions(array $deductions, string $depositCurrency, float $rate): array
    {
        $rows = [];

        foreach ($deductions as $deduction) {
            $amountRaw = $deduction['amount'] ?? null;
            $reason = trim((string) ($deduction['reason'] ?? ''));

            if (($amountRaw === null || $amountRaw === '') && $reason === '') {
                continue;
            }

            $amount = round((float) $amountRaw, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'deductions' => __('Every deposit deduction needs an amount greater than zero.'),
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'deductions' => __('Every deposit deduction needs a reason.'),
                ]);
            }

            $category = $deduction['category'] ?? DepositDeductionCategory::Other;
            if (! $category instanceof DepositDeductionCategory) {
                $category = DepositDeductionCategory::tryFrom((int) $category) ?? DepositDeductionCategory::Other;
            }

            $rows[] = [
                'category' => $category,
                'reason' => $reason,
                'amount' => $amount,
                'currency' => Money::normalize($deduction['currency'] ?? $depositCurrency),
            ];
        }

        return $rows;
    }
}
