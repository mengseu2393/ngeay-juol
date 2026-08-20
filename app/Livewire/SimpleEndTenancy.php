<?php

namespace App\Livewire;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Rental;
use App\Models\Unit;
use App\Support\ActiveProperty;
use Livewire\Component;

/**
 * Simple end-tenancy flow for mobile/PWA.
 * Step 1: pick occupied room → Step 2: confirm details & choose outcome → Step 3: result.
 * Reuses the same update logic as UnitResource::endTenancyAction().
 */
class SimpleEndTenancy extends Component
{
    /** current wizard step: 'pick' | 'confirm' | 'done' */
    public string $step = 'pick';

    public ?int $unitId = null;
    public string $endDate = '';
    public string $status = '';
    public bool $freeRoom = true;

    public ?array $result = null;

    public function mount(): void
    {
        $this->endDate = now()->toDateString();
        $this->status = RentalStatus::Vacated->value;

        $unitId = (int) request()->query('unit_id');
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

    public function submit(): void
    {
        $this->validate([
            'endDate' => 'required|date',
            'status' => 'required|string',
            'unitId' => 'required|integer',
        ]);

        $unit = $this->loadUnit($this->unitId);

        if (! $unit) {
            $this->addError('unitId', __('Room not found.'));
            return;
        }

        $rental = Rental::query()
            ->where('unit_id', $unit->id)
            ->where('status', RentalStatus::Active->value)
            ->first();

        if (! $rental) {
            $this->addError('unitId', __('This room has no active tenancy.'));
            return;
        }

        $rental->update([
            'status' => (int) $this->status,
            'end_date' => $this->endDate,
        ]);

        if ($this->freeRoom) {
            $unit->update(['status' => UnitStatus::Available]);
        }

        $unit->refresh();

        $this->result = [
            'room_number' => $unit->room_number,
            'tenant_name' => $rental->occupant_name ?: ($rental->tenant?->name ?? '—'),
            'unit_status' => $unit->status->getLabel(),
            'rental_status' => RentalStatus::from((int) $this->status)->getLabel(),
        ];

        $this->step = 'done';
    }

    public function reset_form(): void
    {
        $this->reset(['unitId', 'endDate', 'status', 'freeRoom', 'result']);
        $this->endDate = now()->toDateString();
        $this->status = RentalStatus::Vacated->value;
        $this->freeRoom = true;
        $this->step = 'pick';
    }

    private function loadUnit(?int $id): ?Unit
    {
        if (! $id) return null;
        $propertyId = ActiveProperty::id();

        return Unit::query()
            ->with('activeRental.tenant')
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->whereKey($id)
            ->first();
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

        $outcomes = [
            RentalStatus::Vacated->value => RentalStatus::Vacated->getLabel(),
            RentalStatus::Expired->value => RentalStatus::Expired->getLabel(),
        ];

        return view('livewire.simple-end-tenancy', compact('occupiedRooms', 'selectedUnit', 'outcomes'));
    }
}
