<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each room of a property still owes: its unpaid invoices rolled into one
 * row per room, the room that has waited longest first ("priority by due" on
 * the /m2 home sketch). Read-only. Amounts are the invoices' own balance, which
 * is already in the property's currency.
 */
class RoomDueService
{
    /**
     * @return Collection<int, array{unitId: int|null, room: string, tenant: ?string, balance: float, balanceUsd: float, invoices: int, oldest: ?Carbon, daysLate: int}> keyed by unit id
     */
    public static function forProperty(int $propertyId): Collection
    {
        return self::build($propertyId);
    }

    /**
     * The same, across every property the signed-in landlord can see (the
     * Invoice landlord scope does the bounding). Room ids are unique across
     * properties, so the result is keyed the same way.
     */
    public static function forAll(): Collection
    {
        return self::build(null);
    }

    private static function build(?int $propertyId): Collection
    {
        $today = today();

        return Invoice::query()
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->whereIn('payment_status', [InvoiceStatus::Pending->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value])
            ->with('rental.unit')
            ->orderBy('due_date')
            ->get()
            ->filter(fn (Invoice $invoice) => (float) $invoice->balance > 0.009)
            ->groupBy(fn (Invoice $invoice) => $invoice->rental?->unit_id ?? 0)
            ->map(function (Collection $group, $unitId) use ($today) {
                $oldest = $group->min('due_date');

                return [
                    'unitId' => (int) $unitId ?: null,
                    'room' => $group->first()->rental?->unit?->room_number ?? '—',
                    'tenant' => $group->first()->rental?->occupant_name,
                    'balance' => round((float) $group->sum(fn (Invoice $invoice) => (float) $invoice->balance), 2),
                    // The same debt in US dollars (the invoices' own USD twin), for totals that must be one currency.
                    'balanceUsd' => round((float) $group->sum(fn (Invoice $invoice) => (float) $invoice->balance_usd), 2),
                    'invoices' => $group->count(),
                    'oldest' => $oldest,
                    'daysLate' => $oldest && $oldest->lt($today) ? (int) $oldest->diffInDays($today) : 0,
                ];
            })
            ->sortBy([['oldest', 'asc'], ['room', 'asc']]);
    }
}
