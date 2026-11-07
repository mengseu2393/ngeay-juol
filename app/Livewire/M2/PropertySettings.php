<?php

namespace App\Livewire\M2;

use App\Enums\UnitStatus;
use App\Livewire\Mobile\PropertySettings as MobilePropertySettings;
use App\Models\Property;
use App\Models\Unit;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * /m2/settings/property and /m2/settings/property/{section} — the active
 * property's settings. The hub lists five sections; each opens its own screen
 * with its own Save. All the logic (validation, services, permissions, the
 * subscription write lock) is /m's PropertySettings; only the screens and the
 * addresses are /m2's. A saved section goes back to the hub with a toast.
 */
#[Layout('m2.layout')]
class PropertySettings extends MobilePropertySettings
{
    public const SECTIONS = ['details', 'billing', 'move-in', 'contacts', 'opening'];

    /** null = the hub */
    #[Locked]
    public ?string $section = null;

    public function mount(?string $section = null): void
    {
        parent::mount();

        $this->section = in_array($section, self::SECTIONS, true) ? $section : null;

        // Opening readings only exist for metered utilities; without one there is nothing to show.
        if ($this->section === 'opening' && $this->propertyId && $this->meteredUtilities()->isEmpty()) {
            $this->redirectRoute('m2.property', navigate: true);
        }
    }

    /** "Other properties" on the hub: open another of this landlord's properties here (LandlordScope limits the ids). */
    public function switchTo(int $propertyId): void
    {
        $property = Property::query()->find($propertyId);
        abort_unless($property, 404);
        Gate::authorize('view', $property);

        ActiveProperty::set($property->id);

        $this->redirectRoute('m2.property', navigate: true);
    }

    /** A section saved: back to the hub, where the toast says so. */
    protected function saved(string $sheet, string $message): void
    {
        $this->fillAll();
        session()->flash('m2-toast', $message);
        $this->redirectRoute('m2.property', navigate: true);
    }

    public function render()
    {
        $data = parent::render()->getData();
        $property = $data['property'];

        $titles = [
            'details' => __('Property details'),
            'billing' => __('Billing'),
            'move-in' => __('Move-in rules'),
            'contacts' => __('Contacts & property info'),
            'opening' => __('Opening readings'),
        ];

        $others = $property
            ? Property::query()
                ->whereKeyNot($property->id)
                ->withCount([
                    'units',
                    'units as occupied_count' => fn ($q) => $q->where('status', UnitStatus::Occupied->value),
                ])
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.m2.property-settings', $data + [
            'title' => $this->section ? $titles[$this->section] : __('Property Settings'),
            'others' => $others,
            'roomCount' => $property ? $property->units()->count() : 0,
            'canAddRoom' => (bool) auth()->user()?->can('create', Unit::class),
        ])->title($this->section ? $titles[$this->section] : __('Property Settings'));
    }
}
