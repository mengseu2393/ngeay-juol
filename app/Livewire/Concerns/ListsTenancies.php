<?php

namespace App\Livewire\Concerns;

use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Support\ActiveProperty;
use Illuminate\Support\Collection;

/**
 * The tenant directory behind Simple Mode's Tenants screen and the mobile
 * shell's (/m): tenancies of the active property with Active / Has balance due
 * / Ended / All filters, applied server-side so ended/history rows aren't
 * rendered until asked for. Search is client-side over the rendered cards.
 *
 * The using component supplies render() (its own view) and its own
 * $queryString / $listeners.
 */
trait ListsTenancies
{
    use ManagesTenancy;

    /** active | due | ended | all */
    public string $filter = 'active';

    /**
     * Tenancies matching the current filter, sorted by tenant name, each with
     * unit/tenant/occupants and only its unpaid invoices loaded (so
     * totalDueFor() sums the balance due).
     *
     * @return Collection<int, Rental>
     */
    protected function tenancyList(): Collection
    {
        $propertyId = ActiveProperty::id();

        if (! $propertyId) {
            return collect();
        }

        $unpaid = static::unpaidInvoiceStatuses();

        return Rental::query()
            ->with(['unit.property', 'tenant', 'occupants', 'invoices' => fn ($q) => $q->whereIn('payment_status', $unpaid)])
            ->whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
            ->when($this->filter === 'active' || $this->filter === 'due', fn ($q) => $q->where('status', RentalStatus::Active->value))
            ->when($this->filter === 'ended', fn ($q) => $q->where('status', '!=', RentalStatus::Active->value))
            ->when($this->filter === 'due', fn ($q) => $q->whereHas('invoices', fn ($iq) => $iq->whereIn('payment_status', $unpaid)))
            ->get()
            ->sortBy(fn (Rental $rental) => mb_strtolower($rental->occupant_name ?: ($rental->tenant?->name ?? '')))
            ->values();
    }
}
