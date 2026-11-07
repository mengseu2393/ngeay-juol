<?php

namespace App\Livewire\M2;

use App\Enums\UnitStatus;
use App\Livewire\Mobile\AddTenant as MobileAddTenant;
use App\Models\Rental;
use App\Models\Unit;
use App\Services\MeterUtilities;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

/**
 * /m2/rooms/{unit}/tenant — the vacant Room screen's "Add tenant" button. Same
 * form, rules and tenancy creation as /m's AddTenant (StartsTenancy); only the
 * screen is /m2's.
 */
#[Layout('m2.layout')]
class AddTenant extends MobileAddTenant
{
    public function render()
    {
        $unit = Unit::query()->with(['property', 'activeRental'])->findOrFail($this->unitId);
        $available = $unit->status === UnitStatus::Available && ! $unit->activeRental
            && Auth::user()?->can('create', Rental::class);

        return view('livewire.m2.add-tenant', [
            'unit' => $unit,
            'available' => $available,
            'meteredUtilities' => $available && $this->step === 'details' ? MeterUtilities::forUnit($unit) : collect(),
        ])->title(__('Add tenant'));
    }
}
