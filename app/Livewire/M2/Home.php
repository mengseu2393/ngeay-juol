<?php

namespace App\Livewire\M2;

use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Unit;
use App\Services\RoomDueService;
use App\Support\ActiveProperty;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2 — sketch 1. The account holder's picture and the property name on top,
 * then a switch between the landlord's Properties and the Rooms of the one that
 * is open. Rooms come with this month's electricity (E) and water (W) use and are
 * ordered by what they owe, longest-waiting first ("priority by due").
 */
#[Layout('m2.layout')]
class Home extends Component
{
    /** Deep links keep their side and scope (?view=properties, ?scope=property). */
    protected $queryString = [
        'view' => ['except' => 'rooms'],
        'scope' => ['except' => 'all'],
    ];

    /** 'rooms' | 'properties' */
    public string $view = 'rooms';

    /** Rooms of 'all' the landlord's properties (default) or only the open 'property'. */
    public string $scope = 'all';

    public function mount(): void
    {
        if (! in_array($this->view, ['rooms', 'properties'], true)) {
            $this->view = 'rooms';
        }

        if (! in_array($this->scope, ['all', 'property'], true)) {
            $this->scope = 'all';
        }
    }

    /** Back to every room, whatever property it is in. */
    public function showAllRooms(): void
    {
        $this->scope = 'all';
        $this->view = 'rooms';
    }

    public function setView(string $view): void
    {
        if (in_array($view, ['rooms', 'properties'], true)) {
            $this->view = $view;
        }
    }

    /** Open a property and show its rooms. Only a property this landlord can see is accepted (LandlordScope). */
    public function selectProperty(int $propertyId): void
    {
        abort_unless(Property::query()->whereKey($propertyId)->exists(), 404);

        ActiveProperty::set($propertyId);
        $this->scope = 'property';
        $this->view = 'rooms';
    }

    /**
     * @return Collection<int, Property> each with units_count, occupied_count
     */
    private function properties(): Collection
    {
        $properties = Property::query()
            ->withCount([
                'units',
                'units as occupied_count' => fn ($q) => $q->where('status', UnitStatus::Occupied->value),
            ])
            ->orderBy('name')
            ->get();

        return $properties;
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();
        $property = ActiveProperty::model();
        // "Only this property" needs a property to be open; otherwise it is every room.
        $scoped = $this->scope === 'property' && $propertyId !== null;

        $properties = $this->view === 'properties' ? $this->properties() : collect();

        $rooms = collect();
        $due = collect();
        $propertyNames = collect();

        if ($this->view === 'rooms') {
            $due = $scoped ? RoomDueService::forProperty($propertyId) : RoomDueService::forAll();

            $rooms = Unit::query()
                ->with('activeRental')
                ->when($scoped, fn ($q) => $q->where('property_id', $propertyId))
                ->get()
                // Owing rooms first (longest-waiting on top), then the rest by property and room number.
                ->sort(fn (Unit $a, Unit $b) => ($due->has($b->id) <=> $due->has($a->id))
                    ?: ($due->has($a->id) ? $due[$a->id]['oldest']?->timestamp <=> $due[$b->id]['oldest']?->timestamp : 0)
                    ?: ($a->property_id <=> $b->property_id)
                    ?: strnatcasecmp((string) $a->room_number, (string) $b->room_number))
                ->values();

            $propertyNames = Property::query()->whereIn('id', $rooms->pluck('property_id')->unique()->all())->pluck('name', 'id');
        }

        // Total owed (USD) and a late / due / paid-up count.
        $summary = [
            // One figure, always US dollars, whatever currency each property bills in.
            'total' => round((float) $rooms->sum(fn (Unit $r) => $due[$r->id]['balanceUsd'] ?? 0), 2),
            'late' => $rooms->filter(fn (Unit $r) => ($due[$r->id]['daysLate'] ?? 0) > 0)->count(),
            'soon' => $rooms->filter(fn (Unit $r) => $due->has($r->id) && $due[$r->id]['daysLate'] === 0)->count(),
            'paid' => $rooms->filter(fn (Unit $r) => ! $due->has($r->id) && $r->activeRental)->count(),
        ];

        return view('livewire.m2.home', [
            'summary' => $summary,
            'property' => $property,
            'propertyId' => $propertyId,
            'scoped' => $scoped,
            'properties' => $properties,
            'rooms' => $rooms,
            'due' => $due,
            'propertyNames' => $propertyNames,
            'showPropertyName' => $propertyNames->count() > 1,
            'canAddRoom' => (bool) auth()->user()?->can('create', Unit::class) && Property::query()->exists(),
        ])->title(__('Home'));
    }
}
