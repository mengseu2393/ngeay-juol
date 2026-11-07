<?php

namespace App\Livewire\M2;

use App\Livewire\Mobile\UtilityRates as MobileUtilityRates;
use App\Models\Property;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;

/**
 * /m2/settings/utilities — the active property's utility rates as one list
 * with All / Active / Inactive. The filter and scoping are Simple Mode's (via
 * /m's UtilityRates); a row opens its own edit screen (UtilityRate), and the
 * bar opens Add utility and the reading history.
 */
#[Layout('m2.layout')]
class UtilityRates extends MobileUtilityRates
{
    /** The property picker: any property of this landlord (LandlordScope limits the ids). */
    public function switchProperty(int $propertyId): void
    {
        $property = Property::query()->find($propertyId);
        abort_unless($property, 404);
        Gate::authorize('view', $property);

        ActiveProperty::set($property->id);
        $this->redirectRoute('m2.utilities', navigate: true);
    }

    public function render()
    {
        return view('livewire.m2.utility-rates', $this->utilityRatesData() + [
            'property' => ActiveProperty::model(),
            'properties' => Property::query()->orderBy('name')->get(['id', 'name']),
        ])->title(__('Utility Rates'));
    }
}
