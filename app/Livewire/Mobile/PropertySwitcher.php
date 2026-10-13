<?php

namespace App\Livewire\Mobile;

use App\Livewire\PropertySwitcher as BasePropertySwitcher;

/**
 * The property switcher in the /m top bar. Same rules as the panel's
 * (App\Livewire\PropertySwitcher); after a switch it reloads the current screen
 * rather than going to a Filament panel home.
 */
class PropertySwitcher extends BasePropertySwitcher
{
    /**
     * A full reload of the current /m screen (resources/js/mobile.js): every
     * screen re-renders for the new property, and Livewire's in-memory back
     * snapshots of the previous property are dropped with the page.
     */
    protected function afterSwitch(): void
    {
        $this->dispatch('rw-m-property-switched');
    }

    public function render()
    {
        return view('livewire.mobile.property-switcher');
    }
}
