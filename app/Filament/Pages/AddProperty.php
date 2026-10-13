<?php

namespace App\Filament\Pages;

use App\Enums\OnboardingStep;
use App\Models\Property;
use App\Support\ActiveProperty;
use App\Support\SimpleLandlordMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * "Add property" for an established landlord (/app/add-property) — the same
 * address / utilities / rooms & prices / review screens as the signup wizard,
 * but for ONE new property.
 *
 * It reuses LandlordSetup's steps and markup, with three differences that keep
 * it safe next to real data: it never reads or writes the onboarding progress,
 * it only ever touches the property it created (a tampered id for another
 * property is ignored), and removing/regenerating never deletes anything else.
 */
class AddProperty extends LandlordSetup
{
    protected static ?string $slug = 'add-property';

    /** The property created by step 1; locked so a client can't point the wizard at another one. */
    #[Locked]
    public ?int $createdPropertyId = null;

    /**
     * Landlords and their managers only. Platform staff have no landlord of
     * their own, so a property made here would have no owner (landlord_id is
     * auto-filled from the signed-in landlord); they add properties for a
     * chosen landlord in the admin panel instead.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->effectiveLandlordId() !== null
            && $user->can('create', Property::class);
    }

    public function getTitle(): string
    {
        return __('Add property');
    }

    public function mount()
    {
        abort_unless(static::canAccess(), 403);

        $this->step = OnboardingStep::Properties->value;
        $this->loadState();
    }

    public function isAddingProperty(): bool
    {
        return true;
    }

    // One property per run.
    public function addProperty(): void {}

    public function removeProperty(int $index): void {}

    protected function persistStep(OnboardingStep $step): void {}

    protected function wizardProperties(): Builder
    {
        return Property::query()->whereKey($this->createdPropertyId ?? 0);
    }

    protected function removeUnlistedProperties(array $keptIds): void {}

    protected function afterPropertySaved(Property $property): void
    {
        $this->createdPropertyId = $property->id;
    }

    public function finish()
    {
        if ($this->createdPropertyId) {
            ActiveProperty::set($this->createdPropertyId);
        }

        return redirect($this->homeUrl());
    }

    /** Cancel / done: back to where the landlord came from. A property already saved stays saved. */
    public function skip()
    {
        return redirect($this->homeUrl());
    }

    private function homeUrl(): string
    {
        return SimpleLandlordMode::enabledFor(Auth::user())
            ? route('filament.landlord.pages.simple')
            : route('filament.landlord.pages.dashboard');
    }
}
