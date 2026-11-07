<?php

namespace App\Livewire\M2;

use App\Models\Property;
use App\Models\Unit;
use App\Services\RoomDueService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/dashboard/properties/{property} — one property's rooms in Home's list style:
 * rooms that owe first (longest-waiting on top), then the rest by room number. The
 * {property} binding rides the landlord scope, so another landlord's id is a 404.
 */
#[Layout('m2.layout')]
class DashboardProperty extends Component
{
    public Property $property;

    public function render()
    {
        $due = RoomDueService::forProperty($this->property->id);

        $rooms = Unit::query()
            ->with('activeRental')
            ->where('property_id', $this->property->id)
            ->get()
            ->sort(fn (Unit $a, Unit $b) => ($due->has($b->id) <=> $due->has($a->id))
                ?: ($due->has($a->id) ? $due[$a->id]['oldest']?->timestamp <=> $due[$b->id]['oldest']?->timestamp : 0)
                ?: strnatcasecmp((string) $a->room_number, (string) $b->room_number))
            ->values();

        return view('livewire.m2.dashboard-property', [
            'rooms' => $rooms,
            'due' => $due,
            'currency' => Money::forPropertyId($this->property->id),
            'owed' => round((float) $due->sum('balance'), 2),
            'late' => $due->where('daysLate', '>', 0)->count(),
            'occupied' => $rooms->filter(fn (Unit $room) => $room->activeRental)->count(),
        ])->title($this->property->name);
    }
}
