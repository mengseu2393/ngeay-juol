<?php

namespace App\Livewire\M2;

use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Unit;
use App\Services\M2DashboardService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/dashboard/occupancy — how many rooms are let today and in each of the last six
 * months, plus the rooms that need attention (vacant or under repair). Every property,
 * or (?property=ID) one.
 */
#[Layout('m2.layout')]
class DashboardOccupancy extends Component
{
    protected $queryString = ['property' => ['except' => '']];

    public string $property = '';

    public function render()
    {
        $property = $this->property === '' ? null : Property::query()->find((int) $this->property);

        $rooms = Unit::query()
            ->with('property:id,name')
            ->when($property, fn ($q) => $q->where('property_id', $property->id))
            ->get();

        $count = fn (UnitStatus ...$statuses) => $rooms->whereIn('status', $statuses)->count();
        $occupied = $count(UnitStatus::Occupied);

        return view('livewire.m2.dashboard-occupancy', [
            'property' => $property,
            'total' => $rooms->count(),
            'occupied' => $occupied,
            'available' => $count(UnitStatus::Available),
            'other' => $count(UnitStatus::Maintenance, UnitStatus::Unavailable),
            'percent' => $rooms->isEmpty() ? 0 : (int) round($occupied / $rooms->count() * 100),
            'months' => M2DashboardService::occupancyByMonth($rooms->pluck('id')),
            'attention' => $rooms->where('status', '!=', UnitStatus::Occupied)
                ->sort(fn (Unit $a, Unit $b) => strnatcasecmp((string) $a->property?->name.$a->room_number, (string) $b->property?->name.$b->room_number))
                ->values(),
        ])->title(__('Occupancy'));
    }
}
