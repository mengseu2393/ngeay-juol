<?php

namespace App\Services;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\UtilityUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single, centralized invoice-creation path — replaces the old app's five
 * near-identical, copy-pasted invoice builders. Every invoice (rent-only, with
 * utilities, ad-hoc) flows through here, so a data-integrity change is made once.
 */
class InvoiceBuilderService
{
    /**
     * Generate a unique, human-readable invoice number: INV-{landlordId}-{YYYYMM}-{seq}.
     */
    public function generateNumber(int $landlordId, Carbon $period): string
    {
        $prefix = sprintf('INV-%d-%s-', $landlordId, $period->format('Ym'));

        $seq = Invoice::withoutGlobalScopes()
            ->where('landlord_id', $landlordId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->count() + 1;

        do {
            $candidate = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $exists = Invoice::withoutGlobalScopes()->where('invoice_number', $candidate)->exists();
            $seq++;
        } while ($exists);

        return $candidate;
    }

    /**
     * Build and persist an invoice for a rental.
     *
     * @param  array{
     *     rental?: Rental, rental_id?: int,
     *     period_start: Carbon|string, period_end: Carbon|string,
     *     issue_date?: Carbon|string, due_date?: Carbon|string,
     *     include_rent?: bool,
     *     usages?: array<int, UtilityUsage|int>,
     *     adhoc?: array<int, array{description: string, amount: float|string}>,
     *     status?: InvoiceStatus, notes?: string|null
     * }  $data
     */
    public function create(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $rental = $data['rental'] ?? Rental::withoutGlobalScopes()->findOrFail($data['rental_id']);

            $periodStart = Carbon::parse($data['period_start']);
            $periodEnd = Carbon::parse($data['period_end']);
            $issueDate = isset($data['issue_date']) ? Carbon::parse($data['issue_date']) : Carbon::now();
            $dueDate = isset($data['due_date']) ? Carbon::parse($data['due_date']) : $periodEnd->copy()->addDays(7);

            $invoice = new Invoice([
                'rental_id' => $rental->id,
                'invoice_number' => $this->generateNumber($rental->landlord_id, $periodStart),
                'amount_due' => 0,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'payment_status' => ($data['status'] ?? InvoiceStatus::Pending),
                'notes' => $data['notes'] ?? null,
            ]);
            $invoice->landlord_id = $rental->landlord_id;
            $invoice->tenant_id = $rental->tenant_id;
            $invoice->save();

            $total = 0.0;

            // Rent line
            if ($data['include_rent'] ?? true) {
                $rent = (float) $rental->monthly_rent;
                $invoice->lines()->create([
                    'line_type' => InvoiceLineType::Rent,
                    'description' => 'Monthly rent',
                    'quantity' => 1,
                    'unit_price' => $rent,
                    'amount' => $rent,
                ]);
                $total += $rent;
            }

            // Utility lines (priced + waiver-resolved by UtilityBillingService)
            foreach ($data['usages'] ?? [] as $usage) {
                $usage = $usage instanceof UtilityUsage
                    ? $usage
                    : UtilityUsage::withoutGlobalScopes()->findOrFail($usage);

                $charge = UtilityBillingService::resolveCharge($usage);

                $invoice->lines()->create([
                    'line_type' => InvoiceLineType::Utility,
                    'utility_usage_id' => $usage->id,
                    'description' => trim(($usage->propertyUtility?->name ?? 'Utility').' usage'),
                    'quantity' => $charge['quantity'],
                    'unit_price' => $charge['rate'],
                    'amount' => $charge['amount'],
                    'is_waived' => $charge['is_waived'],
                ]);
                $total += $charge['amount'];
            }

            // Ad-hoc lines
            foreach ($data['adhoc'] ?? [] as $line) {
                $amount = (float) $line['amount'];
                $invoice->lines()->create([
                    'line_type' => InvoiceLineType::AdHoc,
                    'description' => $line['description'],
                    'amount' => $amount,
                ]);
                $total += $amount;
            }

            $invoice->amount_due = round($total, 2);
            $invoice->payment_status = $invoice->resolvePaymentStatus();
            $invoice->save();

            return $invoice->refresh();
        });
    }
}
