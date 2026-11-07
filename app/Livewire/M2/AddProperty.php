<?php

namespace App\Livewire\M2;

use App\Livewire\Mobile\AddProperty as MobileAddProperty;
use Livewire\Attributes\Layout;

/**
 * /m2/settings/property/new — add a property. /m's AddProperty does all of it
 * (one all-or-nothing PropertySetupService::create, the plan's property/room
 * limits, the new property becoming the active one); only the screen and where
 * it lands afterwards are /m2's.
 */
#[Layout('m2.layout')]
class AddProperty extends MobileAddProperty
{
    protected function createdUrl(): string
    {
        session()->flash('m2-toast', __('Property added'));

        return $this->withRooms ? route('m2.home') : route('m2.property');
    }

    public function render()
    {
        return view('livewire.m2.add-property', parent::render()->getData())->title(__('Add property'));
    }
}
