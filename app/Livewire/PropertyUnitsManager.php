<?php

namespace App\Livewire;

use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Unit;
use App\Support\SimpleLandlordMode;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Units" tab of a property's settings page: every room as a phone-friendly
 * card. Each card opens the room's own page (App\Filament\Pages\UnitOverview),
 * where the agreement, readings, history and reset live.
 */
class PropertyUnitsManager extends Component
{
    #[Locked]
    public int $propertyId;

    public string $search = '';

    /** 'all' | 'vacant' | 'occupied' */
    public string $filter = 'all';

    /** Sibling Simple Mode screens stay mounted on one page; re-render when they change shared data. */
    protected $listeners = [
        'tenancy-started' => '$refresh',
        'tenancy-ended' => '$refresh',
    ];

    public function mount(int $propertyId): void
    {
        // Property is LandlordScope-filtered: another landlord's id 404s.
        $property = Property::query()->findOrFail($propertyId);
        abort_unless(auth()->user()?->can('view', $property), 403);

        $this->propertyId = $property->getKey();
    }

    public function render()
    {
        $units = Unit::query()
            ->with(['activeRental.tenant'])
            ->where('property_id', $this->propertyId)
            ->when($this->filter === 'vacant', fn ($q) => $q->where('status', UnitStatus::Available->value))
            ->when($this->filter === 'occupied', fn ($q) => $q->where('status', UnitStatus::Occupied->value))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('room_number', 'like', '%'.trim($this->search).'%'))
            ->orderBy('floor_number')
            ->orderBy('room_number')
            ->get();

        $counts = Unit::query()->where('property_id', $this->propertyId)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        // Simple Mode users (and phones, which are locked to it) open the room inside Simple Mode.
        $simple = SimpleLandlordMode::enabledFor(auth()->user()) || SimpleLandlordMode::isLocked(request());

        return view('livewire.property-units-manager', [
            'simple' => $simple,
            'units' => $units,
            'total' => (int) $counts->sum(),
            'vacant' => (int) ($counts[UnitStatus::Available->value] ?? 0),
            'occupied' => (int) ($counts[UnitStatus::Occupied->value] ?? 0),
        ]);
    }
}
