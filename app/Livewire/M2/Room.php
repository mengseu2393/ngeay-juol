<?php

namespace App\Livewire\M2;

use App\Enums\BillingType;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/rooms/{unit} — sketches ② and ④: the room's name on top, then who lives
 * there, its meters (electricity E, water W), its price, its other services
 * (parking, lift, sanitation…) and its latest bill. Every row opens the screen
 * that does the work; "Edit room" opens M2\EditRoom. The {unit} binding runs
 * through the landlord scope, so another landlord's room is a 404.
 */
#[Layout('m2.layout')]
class Room extends Component
{
    public Unit $unit;

    public function mount(Unit $unit): void
    {
        $this->unit = $unit;

        // A deep link into another of the landlord's properties opens that property, so Back lands in the right list.
        if (ActiveProperty::id() !== $unit->property_id) {
            ActiveProperty::set($unit->property_id);
        }
    }

    /** A vacant room that isn't Available (Maintenance / Unavailable) is let again with one tap. */
    public function markAvailable(): void
    {
        abort_unless(auth()->user()?->can('update', $this->unit), 403);

        $unit = Unit::query()->with('activeRental')->findOrFail($this->unit->id);
        abort_if($unit->activeRental, 422);

        $unit->update(['status' => UnitStatus::Available]);
        $this->unit = $unit;

        $this->dispatch('rw-m-toast', message: __('Room is available for a new tenant.'));
    }

    public function render()
    {
        $unit = $this->unit->load('activeRental', 'property');
        $rental = $unit->activeRental;

        $utilities = PropertyUtility::query()
            ->where('property_id', $unit->property_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $meters = $utilities->filter(fn (PropertyUtility $u) => $u->billing_type !== BillingType::Flat)->values();
        $services = $utilities->filter(fn (PropertyUtility $u) => $u->billing_type === BillingType::Flat)->values();

        // Newest reading of each meter of this room, in one query.
        $latest = UtilityUsage::query()
            ->where('unit_id', $unit->id)
            ->whereIn('property_utility_id', $meters->modelKeys())
            ->orderByDesc('reading_date')->orderByDesc('id')
            ->get()
            ->unique('property_utility_id')
            ->keyBy('property_utility_id');

        $bill = $rental
            ? Invoice::query()->where('rental_id', $rental->id)->orderByDesc('issue_date')->orderByDesc('id')->first()
            : null;

        return view('livewire.m2.room', [
            'unit' => $unit,
            'rental' => $rental,
            'meters' => $meters,
            'services' => $services,
            'latest' => $latest,
            'bill' => $bill,
            'canMarkAvailable' => ! $rental && $unit->status !== UnitStatus::Available && (bool) auth()->user()?->can('update', $unit),
            'canAddTenant' => ! $rental && $unit->status === UnitStatus::Available && (bool) auth()->user()?->can('create', Rental::class),
            'canCreateInvoice' => (bool) auth()->user()?->can('create', Invoice::class),
            'canEditRoom' => (bool) auth()->user()?->can('update', $unit),
            'price' => Money::format($unit->rent_amount, Money::forRecord($unit)),
        ])->title(__('Room').' '.$unit->room_number);
    }
}
