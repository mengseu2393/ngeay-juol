<?php

namespace App\Services;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\ReadingType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects one past meter reading and re-chains the readings after it.
 *
 * A reading's `old_reading` / `amount_used` are derived from the reading before
 * it (see MeterReadingResolver::baselineFor()), so changing one index changes
 * the next reading's usage too. Both `preview()` (read-only, drives the
 * confirmation step) and `apply()` share the same in-memory recompute so what the
 * landlord confirms is exactly what gets saved.
 *
 * The chain is per (unit, utility, meter) — a replaced meter starts a new chain,
 * so no subtraction ever crosses a swap.
 */
class UtilityReadingCorrectionService
{
    /** Readings that can be corrected: a real index was recorded. */
    public function isEditable(UtilityUsage $usage): bool
    {
        return $usage->new_reading !== null && $usage->reading_type !== ReadingType::Fixed;
    }

    /**
     * Rows are every reading whose stored values would change (the edited one
     * first), each with before/after date, old_reading, new_reading, amount_used.
     *
     * @return array{rows: array<int, array<string, mixed>>, error: ?string}
     */
    public function preview(UtilityUsage $usage, float $newReading, string $date): array
    {
        $plan = $this->plan($usage, $newReading, $date);

        return ['rows' => $plan['rows'], 'error' => $plan['error']];
    }

    /**
     * @throws ValidationException when the correction is invalid or blocked by a locked invoice
     */
    public function apply(UtilityUsage $usage, float $newReading, string $date, ?ReadingType $type = null): void
    {
        DB::transaction(function () use ($usage, $newReading, $date, $type): void {
            $plan = $this->plan($usage, $newReading, $date);

            if ($plan['error']) {
                throw ValidationException::withMessages(['new_reading' => $plan['error']]);
            }

            foreach ($plan['models'] as $id => $values) {
                $row = $plan['byId'][$id];
                $row->forceFill($values);
                if ($id === $usage->getKey() && $type) {
                    $row->reading_type = $type;
                }
                $row->save();

                $this->repriceDraftLine($row);
            }
        });
    }

    /**
     * @return array{rows: array, error: ?string, models: array<int, array>, byId: array<int, UtilityUsage>}
     */
    private function plan(UtilityUsage $usage, float $newReading, string $date): array
    {
        $fail = fn (string $message) => ['rows' => [], 'error' => $message, 'models' => [], 'byId' => []];

        if (! $this->isEditable($usage)) {
            return $fail(__('This reading cannot be corrected.'));
        }
        if ($newReading < 0 || $newReading > 999999999) {
            return $fail(__('Enter a valid meter reading.'));
        }
        if ($date > now()->toDateString()) {
            return $fail(__('The reading date cannot be in the future.'));
        }

        $meter = $usage->meter_id ? UtilityMeter::withoutGlobalScopes()->find($usage->meter_id) : null;

        if ($meter && $meter->installed_on && $date < $meter->installed_on->toDateString()) {
            return $fail(__('The reading date is before this meter was installed.'));
        }

        // Same room + utility on the same day is one reading (re-saving updates it).
        $clash = UtilityUsage::query()
            ->where('unit_id', $usage->unit_id)
            ->where('property_utility_id', $usage->property_utility_id)
            ->whereDate('reading_date', $date)
            ->whereKeyNot($usage->getKey())
            ->exists();

        if ($clash) {
            return $fail(__('There is already a reading on that date.'));
        }

        $chain = $this->chain($usage);
        $byId = $chain->keyBy(fn (UtilityUsage $u) => $u->getKey())->all();

        // Apply the edit in memory, then re-sort (the date may have moved).
        $items = $chain->map(function (UtilityUsage $u) use ($usage, $newReading, $date) {
            $isTarget = $u->getKey() === $usage->getKey();

            return [
                'id' => $u->getKey(),
                'model' => $u,
                'date' => $isTarget ? $date : $u->reading_date->toDateString(),
                'new' => $isTarget ? $newReading : (float) $u->new_reading,
            ];
        })->sort(fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']])->values();

        $rows = [];
        $models = [];
        $prior = null;

        foreach ($items as $i => $item) {
            /** @var UtilityUsage $u */
            $u = $item['model'];

            if ($u->is_reset) {
                // A reset is a new starting point: nothing is measured across it.
                $old = $item['new'];
            } elseif ($prior !== null) {
                $old = $prior;
            } elseif ($meter) {
                $old = (float) $meter->installed_reading;
            } else {
                $old = $item['new']; // first reading without a meter is a zero-usage baseline
            }

            if (! $u->is_reset && ! $meter?->digits && $item['new'] < $old) {
                return $fail(__('The reading cannot be lower than the previous one (:value).', [
                    'value' => rtrim(rtrim(number_format($old, 3), '0'), '.'),
                ]));
            }

            $amount = $u->is_reset
                ? 0.0
                : ($meter
                    ? $meter->consumption($old, $item['new'])
                    : round(max(0, $item['new'] - $old), 3));

            $prior = $item['new'];

            $before = [
                'date' => $u->reading_date->toDateString(),
                'old_reading' => round((float) $u->old_reading, 3),
                'new_reading' => round((float) $u->new_reading, 3),
                'amount_used' => round((float) $u->amount_used, 3),
            ];
            $after = [
                'date' => $item['date'],
                'old_reading' => round($old, 3),
                'new_reading' => round($item['new'], 3),
                'amount_used' => round($amount, 3),
            ];

            $isTarget = $item['id'] === $usage->getKey();

            if (! $isTarget && $before === $after) {
                continue;
            }

            $invoice = $this->lockedInvoiceFor($u);
            if ($invoice) {
                return $fail(__('This reading (or a later one it affects) is already billed on invoice :number. Cancel or revise that invoice first.', [
                    'number' => $invoice->invoice_number,
                ]));
            }

            $models[$item['id']] = [
                'reading_date' => $after['date'],
                'old_reading' => $after['old_reading'],
                'new_reading' => $after['new_reading'],
                'amount_used' => $after['amount_used'],
            ];
            $rows[] = [
                'id' => $item['id'],
                'is_target' => $isTarget,
                'before' => $before,
                'after' => $after,
            ];
        }

        usort($rows, fn ($a, $b) => $b['is_target'] <=> $a['is_target']);

        return ['rows' => $rows, 'error' => null, 'models' => $models, 'byId' => $byId];
    }

    /** All real-index readings of the same room + utility + meter. */
    private function chain(UtilityUsage $usage): Collection
    {
        return UtilityUsage::query()
            ->where('unit_id', $usage->unit_id)
            ->where('property_utility_id', $usage->property_utility_id)
            ->when(
                $usage->meter_id,
                fn ($q) => $q->where('meter_id', $usage->meter_id),
                fn ($q) => $q->whereNull('meter_id'),
            )
            ->whereNotNull('new_reading')
            ->where('reading_type', '!=', ReadingType::Fixed->value)
            ->orderBy('reading_date')
            ->orderBy('id')
            ->get();
    }

    /** The invoice that bills this reading when it is past Draft (and not Cancelled). */
    private function lockedInvoiceFor(UtilityUsage $usage): ?Invoice
    {
        $invoice = InvoiceLine::query()
            ->where('utility_usage_id', $usage->getKey())
            ->first()
            ?->invoice()->withoutGlobalScopes()->first();

        if (! $invoice) {
            return null;
        }

        return in_array($invoice->payment_status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true)
            ? null
            : $invoice;
    }

    /** Keep a Draft invoice's utility line in step with the corrected usage. */
    private function repriceDraftLine(UtilityUsage $usage): void
    {
        $line = InvoiceLine::query()
            ->where('line_type', InvoiceLineType::Utility)
            ->where('utility_usage_id', $usage->getKey())
            ->first();

        $invoice = $line?->invoice()->withoutGlobalScopes()->first();

        if (! $line || $invoice?->payment_status !== InvoiceStatus::Draft) {
            return;
        }

        $charge = UtilityBillingService::resolveCharge($usage->fresh('propertyUtility'));
        $currency = Money::normalize($charge['currency'] ?? 'USD');
        $rate = (float) ($line->exchange_rate ?: $invoice->usd_khr_rate ?: 0);

        $line->update([
            'quantity' => $charge['quantity'],
            'unit_price' => $charge['rate'],
            'amount' => $charge['amount'],
            'is_waived' => $charge['is_waived'],
            'amount_usd' => Money::convert($charge['amount'], $currency, 'USD', $rate),
            'amount_khr' => Money::convert($charge['amount'], $currency, 'KHR', $rate),
        ]);
    }
}
