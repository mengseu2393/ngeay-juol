<?php

namespace App\Livewire;

use App\Models\Property;
use App\Support\ActiveProperty;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The property context switcher rendered at the top of the admin sidebar
 * (PanelsRenderHook::SIDEBAR_NAV_START). Selecting a property writes the
 * {@see ActiveProperty} session context and reloads so the sidebar re-points.
 */
class PropertySwitcher extends Component
{
    /** Bound to the <select>; '' means the landlord-wide "All properties" view. */
    public string $propertyId = '';

    public function mount(): void
    {
        $this->propertyId = (string) (ActiveProperty::id() ?? '');
    }

    /** Fired by wire:model.live when the landlord picks a property. */
    public function updatedPropertyId(string $value): void
    {
        ActiveProperty::set($value === '' ? null : $value);

        // Full reload so server-computed navigation (visibility + the context
        // group) is rebuilt for the new context. Land on the panel home.
        $this->redirect(Filament::getUrl(), navigate: false);
    }

    /** Whether this user gets a switcher at all (landlords, managers, staff). */
    public function isEligible(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        // canAccessPanel() already limits this panel to staff/landlord/manager;
        // an unassigned manager (no effectiveLandlordId) is the only one with
        // nothing to switch between.
        return $user->isPlatformStaff() || $user->effectiveLandlordId() !== null;
    }

    /** Properties this user may switch between (already LandlordScope-filtered). */
    public function properties(): Collection
    {
        return Property::query()->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        return view('livewire.property-switcher');
    }
}
