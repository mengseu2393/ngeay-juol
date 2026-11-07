<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The month-by-month numbers behind the /m2 dashboard screens (income, occupancy,
 * one room's history). Read-only; every query rides the landlord scope of the
 * model it reads, and Payment — which has none — is bounded through its invoice.
 */
class M2DashboardService
{
    /** How many months the lists reach back (this one included). */
    public const MONTHS = 6;

    /**
     * Income per month, newest first, months with no payment included as zero.
     * A payment may have been taken in the other currency, so the sum reads the
     * payment's own USD / KHR twin (converted at the rate saved on it).
     *
     * @param  'USD'|'KHR'  $currency
     * @return Collection<string, array{label: string, count: int, total: float}> keyed 'Y-m'
     */
    public static function incomeByMonth(?int $propertyId, string $currency, int $count = self::MONTHS): Collection
    {
        $first = now()->startOfMonth()->subMonthsNoOverflow($count - 1);

        $payments = Payment::query()
            ->whereBetween('paid_at', [$first, now()->endOfMonth()])
            ->whereHas('invoice', fn ($q) => $q->when($propertyId, fn ($iq) => $iq->where('property_id', $propertyId)))
            ->get(['id', 'paid_at', 'amount_usd', 'amount_khr'])
            ->groupBy(fn (Payment $payment) => $payment->paid_at->format('Y-m'));

        return collect(range(0, $count - 1))->map(function (int $i) use ($first, $payments, $currency) {
            $month = $first->copy()->addMonthsNoOverflow($i);
            $group = $payments->get($month->format('Y-m'), collect());

            return [$month->format('Y-m'), [
                'label' => $month->translatedFormat('F Y'),
                'short' => $month->translatedFormat('M'),
                'count' => $group->count(),
                'total' => (float) $group->sum(fn (Payment $p) => (float) ($currency === 'KHR' ? $p->amount_khr : $p->amount_usd)),
            ]];
        })->reverse()->mapWithKeys(fn (array $pair) => [$pair[0] => $pair[1]]);
    }

    /**
     * Occupancy for each of the last months, newest first: the share of the given
     * rooms that had a tenancy at any point in that month. A tenancy ends on its
     * move-out date, or — once it is no longer active — its end date.
     *
     * @param  Collection<int, int>  $unitIds
     * @return Collection<int, array{label: string, occupied: int, total: int, percent: int}>
     */
    public static function occupancyByMonth(Collection $unitIds, int $count = self::MONTHS): Collection
    {
        $total = $unitIds->count();
        $first = now()->startOfMonth()->subMonthsNoOverflow($count - 1);

        $endOf = fn (Rental $rental) => $rental->move_out_date ?? ($rental->status === RentalStatus::Active ? null : $rental->end_date);

        $rentals = $total === 0 ? collect() : Rental::query()
            ->whereIn('unit_id', $unitIds)
            ->whereDate('start_date', '<=', now()->endOfMonth())
            ->get(['unit_id', 'status', 'start_date', 'end_date', 'move_out_date'])
            ->filter(fn (Rental $rental) => ($ends = $endOf($rental)) === null || $ends->gte($first));

        return collect(range(0, $count - 1))->map(function (int $i) use ($first, $rentals, $total, $endOf) {
            $start = $first->copy()->addMonthsNoOverflow($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $occupied = $rentals
                ->filter(fn (Rental $rental) => $rental->start_date->lte($end) && ($endOf($rental) === null || $endOf($rental)->gte($start)))
                ->pluck('unit_id')->unique()->count();

            return [
                'label' => $start->translatedFormat('F Y'),
                'occupied' => $occupied,
                'total' => $total,
                'percent' => $total === 0 ? 0 : (int) round($occupied / $total * 100),
            ];
        })->reverse()->values();
    }

    /**
     * One room's readings and bills, grouped by month (newest first), reaching
     * back $months months. $kind is 'all', 'bill' or a property_utility id.
     *
     * @return Collection<string, array{label: string, billed: float, paid: float, items: Collection}> keyed 'Y-m'
     */
    public static function roomHistory(Unit $unit, string $kind, int $months = self::MONTHS): Collection
    {
        $since = now()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $items = collect();

        if ($kind !== 'bill') {
            $usages = $unit->utilityUsages()
                ->with('propertyUtility')
                ->where('reading_date', '>=', $since)
                ->when($kind !== 'all', fn ($q) => $q->where('property_utility_id', (int) $kind))
                ->get();

            $items = $items->concat($usages->map(fn (UtilityUsage $usage) => ['type' => 'usage', 'date' => $usage->reading_date, 'usage' => $usage]));
        }

        if ($kind === 'all' || $kind === 'bill') {
            $invoices = $unit->invoices()->where('issue_date', '>=', $since)->get();

            $items = $items->concat($invoices->map(fn (Invoice $invoice) => ['type' => 'bill', 'date' => $invoice->issue_date, 'invoice' => $invoice]));
        }

        return $items
            ->sortByDesc(fn (array $item) => $item['date']->timestamp)
            ->groupBy(fn (array $item) => $item['date']->format('Y-m'))
            ->map(function (Collection $group, string $ym) {
                $bills = $group->where('type', 'bill')->pluck('invoice')->filter(fn (Invoice $i) => $i->payment_status !== InvoiceStatus::Cancelled);

                return [
                    'label' => Carbon::createFromFormat('!Y-m', $ym)->translatedFormat('F Y'),
                    'billed' => (float) $bills->sum('amount_due'),
                    'paid' => (float) $bills->sum('amount_paid'),
                    'items' => $group->values(),
                ];
            });
    }
}
