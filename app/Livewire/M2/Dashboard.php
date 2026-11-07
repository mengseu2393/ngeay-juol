<?php

namespace App\Livewire\M2;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Services\RoomDueService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/dashboard — the landlord's properties in the same quiet list as Home, with
 * this month's income, what is owed, occupancy and bills on top. Tapping a property
 * opens its rooms (DashboardProperty); Income and Occupancy open their own month-
 * by-month screens. Read-only; Property, Unit and Invoice are landlord-scoped, and
 * Payment is bounded through its invoice.
 *
 * Totals across properties are in US dollars (the payment's / invoice's USD twin),
 * because the properties may bill in different currencies; a property's own row is
 * shown in its own currency.
 */
#[Layout('m2.layout')]
class Dashboard extends Component
{
    public function render()
    {
        $monthStart = now()->startOfMonth();
        $lastStart = $monthStart->copy()->subMonthNoOverflow();

        $properties = Property::query()
            ->withCount([
                'units',
                'units as occupied_count' => fn ($q) => $q->where('status', UnitStatus::Occupied->value),
            ])
            ->orderBy('name')
            ->get();

        // This month's and last month's payments in one pass, tagged with the invoice's property.
        $payments = Payment::query()
            ->whereBetween('paid_at', [$lastStart, now()->endOfMonth()])
            ->whereHas('invoice')
            ->with('invoice:id,property_id')
            ->get(['id', 'invoice_id', 'paid_at', 'amount_usd', 'amount_khr']);

        $thisMonth = $payments->filter(fn (Payment $p) => $p->paid_at->gte($monthStart));
        $before = (float) $payments->reject(fn (Payment $p) => $p->paid_at->gte($monthStart))->sum(fn (Payment $p) => (float) $p->amount_usd);
        $income = (float) $thisMonth->sum(fn (Payment $p) => (float) $p->amount_usd);

        $due = RoomDueService::forAll();
        $propertyOfRoom = Unit::query()->whereIn('id', $due->keys())->pluck('property_id', 'id');

        $rows = $properties->map(function (Property $property) use ($thisMonth, $due, $propertyOfRoom) {
            $currency = Money::forPropertyId($property->id);
            $mine = $thisMonth->filter(fn (Payment $p) => $p->invoice?->property_id === $property->id);
            $owing = $due->filter(fn (array $row) => ($propertyOfRoom[$row['unitId']] ?? null) === $property->id);

            return [
                'property' => $property,
                'currency' => $currency,
                'income' => (float) $mine->sum(fn (Payment $p) => (float) ($currency === 'KHR' ? $p->amount_khr : $p->amount_usd)),
                'late' => $owing->where('daysLate', '>', 0)->count(),
                'owing' => $owing->count(),
            ];
        });

        $rooms = (int) $properties->sum('units_count');

        return view('livewire.m2.dashboard', [
            'rows' => $rows,
            'income' => $income,
            'trend' => $before > 0 ? (int) round(($income - $before) / $before * 100) : null,
            'owed' => round((float) $due->sum('balanceUsd'), 2),
            'occupancy' => $rooms === 0 ? 0 : (int) round($properties->sum('occupied_count') / $rooms * 100),
            'bills' => Invoice::query()
                ->whereBetween('issue_date', [$monthStart, now()->endOfMonth()])
                ->where('payment_status', '!=', InvoiceStatus::Cancelled->value)
                ->count(),
        ])->title(__('Dashboard'));
    }
}
