<?php

namespace App\Livewire;

use App\Filament\Pages\AddProperty;
use App\Models\Property;
use App\Models\User;
use App\Support\ActiveProperty;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The property context switcher rendered at the top of the admin sidebar
 * (PanelsRenderHook::SIDEBAR_NAV_START). Selecting a property writes the
 * {@see ActiveProperty} session context and reloads so the sidebar re-points.
 */
class PropertySwitcher extends Component
{
    /** Sentinel <option> value: "+ Add property" lives inside the dropdown. */
    public const ADD = '__add__';

    /** Bound to the <select>; '' only for platform staff who haven't picked a property yet. */
    public string $propertyId = '';

    public function mount(): void
    {
        // There is no "All properties" choice any more: a landlord/manager who
        // is still on the old landlord-wide view (or has no pick yet) lands in
        // their first property. Platform staff are unscoped, so they pick one.
        if (ActiveProperty::id() === null && $this->isEligible() && ! Auth::user()->isPlatformStaff()) {
            $first = $this->properties()->first();

            if ($first) {
                ActiveProperty::set($first->id);
            }
        }

        $this->propertyId = (string) (ActiveProperty::id() ?? '');
    }

    /** "Add property" is offered only to users the PropertyPolicy lets create one. */
    public function canAddProperty(): bool
    {
        return AddProperty::canAccess();
    }

    /** Fired by wire:model.live when the landlord picks a property. */
    public function updatedPropertyId(string $value): void
    {
        if ($value === '') {
            return;
        }

        if ($value === self::ADD) {
            // Restore the visible pick, then go to the create page — only if allowed.
            $this->propertyId = (string) (ActiveProperty::id() ?? '');
            abort_unless($this->canAddProperty(), 403);

            $this->redirect($this->addPropertyUrl(), navigate: false);

            return;
        }

        // Fails closed: only a property this user can see (LandlordScope) is accepted.
        abort_unless(Property::query()->whereKey($value)->exists(), 404);

        ActiveProperty::set($value);

        $this->afterSwitch();
    }

    /**
     * Full reload so server-computed navigation (visibility + the context group)
     * is rebuilt for the new context. Land on the panel home. The mobile shell's
     * switcher overrides this — it has no Filament panel to go back to.
     */
    protected function afterSwitch(): void
    {
        $this->redirect(Filament::getUrl(), navigate: false);
    }

    protected function addPropertyUrl(): string
    {
        return AddProperty::getUrl();
    }

    /** Whether this user gets a switcher at all (landlords, managers, staff). */
    public function isEligible(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        // canAccessPanel() already limits this panel to staff/landlord/manager;
        // an unassigned manager (no effectiveLandlordId) is the only one with
        // nothing to switch between.
        return $user->isPlatformStaff() || $user->effectiveLandlordId() !== null;
    }

    /**
     * Properties this user may switch between (already LandlordScope-filtered).
     *
     * Platform staff are unscoped by LandlordScope, so without the extra filter
     * below they'd see every landlord's properties in one flat list. Once a
     * property is active, staff are narrowed to that property's landlord so the
     * switcher stays inside the landlord they're currently working in.
     */
    public function properties(): Collection
    {
        $query = Property::query();

        $landlordId = ActiveProperty::model()?->landlord_id;

        if ($landlordId !== null) {
            $query->where('landlord_id', $landlordId);
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        return view('livewire.property-switcher');
    }
}
