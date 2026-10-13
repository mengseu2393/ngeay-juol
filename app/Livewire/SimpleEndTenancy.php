<?php

namespace App\Livewire;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\Concerns\EndsTenancy;
use App\Models\Unit;
use App\Support\ActiveProperty;
use Livewire\Component;

/**
 * Simple end-tenancy flow for mobile/PWA.
 * Step 1: pick occupied room → Step 2: confirm details & choose outcome → Step 3: result.
 * Reuses the same update logic as UnitResource::endTenancyAction(). The form
 * and its submit are {@see EndsTenancy} (shared with the /m shell); the room
 * picker is this screen's own.
 */
class SimpleEndTenancy extends Component
{
    use EndsTenancy;

    /** current wizard step: 'pick' | 'confirm' | 'done' */
    public string $step = 'pick';

    public ?int $unitId = null;

    /** Sibling Simple Mode screens stay mounted on one page; re-render when they change shared data. */
    protected $listeners = ['tenancy-started' => '$refresh'];

    public function mount(?int $unitId = null): void
    {
        $this->endDate = now()->toDateString();
        $this->status = RentalStatus::Vacated->value;

        $unitId = $unitId ?? (int) request()->query('unit_id');
        $unit = $unitId ? $this->loadUnit($unitId) : null;

        if ($unit && $unit->status === UnitStatus::Occupied) {
            $this->pickRoom($unit->id);
        }
    }

    public function pickRoom(int $unitId): void
    {
        $this->unitId = $unitId;
        $this->step = 'confirm';
        $this->endDate = now()->toDateString();
        $this->status = RentalStatus::Vacated->value;
        $this->freeRoom = true;
    }

    public function backToPick(): void
    {
        $this->step = 'pick';
        $this->unitId = null;
        $this->result = null;
    }

    public function reset_form(): void
    {
        $this->reset(['unitId', 'endDate', 'status', 'freeRoom', 'result']);
        $this->endDate = now()->toDateString();
        $this->status = RentalStatus::Vacated->value;
        $this->freeRoom = true;
        $this->step = 'pick';
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();

        $occupiedRooms = $propertyId
            ? Unit::query()
                ->with('activeRental.tenant')
                ->where('property_id', $propertyId)
                ->where('status', UnitStatus::Occupied->value)
                ->orderBy('room_number')
                ->get()
            : collect();

        $selectedUnit = $this->unitId ? $this->loadUnit($this->unitId) : null;

        $outcomes = $this->endOutcomes();

        return view('livewire.simple-end-tenancy', compact('occupiedRooms', 'selectedUnit', 'outcomes'));
    }
}
