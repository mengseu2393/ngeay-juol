<?php

namespace App\Livewire\M2;

use App\Enums\BillingType;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Services\ChargeRuleResolver;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/rooms/{unit}/service/{utility} — sketch 2's Parking / Lift / Sanitation:
 * the room and its tenant, what the service costs, and the room's other
 * services as the sketch's row of dots. A flat monthly charge has nothing to
 * read, so the screen is read-only; the price is set in the property's
 * utility rates.
 */
#[Layout('m2.layout')]
class Service extends Component
{
    public Unit $unit;

    public PropertyUtility $utility;

    public function mount(Unit $unit, PropertyUtility $utility): void
    {
        // Only a flat charge of this room's own property — a meter has its own screen.
        abort_unless($utility->property_id === $unit->property_id && $utility->billing_type === BillingType::Flat, 404);

        $this->unit = $unit;
        $this->utility = $utility;
    }

    /**
     * Where this charge lands for the room's current tenant, from the same charge
     * rules the invoice builder uses (a tenant / room / property override or a
     * waiver). A flat charge with no override is on every invoice; a vacant
     * room has no invoices, so there is nothing to say.
     */
    private function addedTo(): ?string
    {
        $rental = $this->unit->activeRental;

        if (! $rental) {
            return null;
        }

        $decision = app(ChargeRuleResolver::class)->resolve([
            'property_utility_id' => $this->utility->id,
            'rental_id' => $rental->id,
            'unit_id' => $this->unit->id,
            'property_id' => $this->unit->property_id,
        ]);

        $state = (string) $decision['effective_state'];

        return match ($state) {
            'not_applicable' => __('Not charged to this room'),
            'skipped_this_cycle' => __('Skipped this cycle'),
            'free', 'waived', 'custom' => __('Every invoice').' · '.ChargeRuleResolver::stateLabel($state),
            default => __('Every invoice'),
        };
    }

    public function render()
    {
        return view('livewire.m2.service', [
            'unit' => $this->unit->load('activeRental'),
            'utility' => $this->utility,
            'addedTo' => $this->addedTo(),
            'services' => PropertyUtility::query()
                ->where('property_id', $this->unit->property_id)
                ->where('is_active', true)
                ->where('billing_type', BillingType::Flat->value)
                ->orderBy('name')
                ->get(),
        ])->title(__($this->utility->name));
    }
}
