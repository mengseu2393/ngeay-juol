<?php

namespace App\Livewire;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Rental;
use App\Models\Unit;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Simple add-tenant flow for mobile/PWA.
 * Step 1: pick vacant room → Step 2: enter tenant info → Step 3: confirm result.
 * Required fields only. Advanced fields are deferred to Full Mode.
 * Uses existing rental/tenancy rules and RoomAccountService.
 */
class SimpleAddTenant extends Component
{
    /** current wizard step: 'pick' | 'details' | 'done' */
    public string $step = 'pick';

    public ?int $unitId = null;
    public string $occupantName = '';
    public string $occupantPhone = '';
    public string $startDate = '';
    public string $monthlyRent = '';

    /** Result from creation */
    public ?array $result = null;

    public function mount(): void
    {
        $this->startDate = now()->toDateString();

        $unitId = (int) request()->query('unit_id');
        $unit = $unitId ? $this->loadUnit($unitId) : null;

        if ($unit && $unit->status === UnitStatus::Available) {
            $this->pickRoom($unit->id);
        }
    }

    public function pickRoom(int $unitId): void
    {
        $this->unitId = $unitId;
        $unit = $this->loadUnit($unitId);
        if ($unit) {
            $this->monthlyRent = (string) $unit->rent_amount;
        }
        $this->step = 'details';
    }

    public function backToPick(): void
    {
        $this->step = 'pick';
        $this->unitId = null;
        $this->occupantName = '';
        $this->occupantPhone = '';
        $this->monthlyRent = '';
        $this->result = null;
    }

    public function submit(): void
    {
        $this->validate([
            'occupantName' => 'required|string|max:255',
            'occupantPhone' => 'nullable|string|max:50',
            'startDate' => 'required|date',
            'monthlyRent' => 'required|numeric|min:0',
            'unitId' => 'required|integer',
        ]);

        $unit = $this->loadUnit($this->unitId);

        if (! $unit) {
            $this->addError('unitId', __('Room not found or not available.'));
            return;
        }

        // Check for existing active tenancy on the room
        $hasActive = Rental::query()
            ->where('unit_id', $unit->id)
            ->where('status', RentalStatus::Active->value)
            ->exists();

        if ($hasActive) {
            $this->addError('unitId', __('This room already has an active tenancy.'));
            return;
        }

        $rental = new Rental([
            'landlord_id' => $unit->landlord_id,
            'unit_id' => $unit->id,
            'occupant_name' => trim($this->occupantName),
            'occupant_phone' => trim($this->occupantPhone) ?: null,
            'monthly_rent' => (float) $this->monthlyRent,
            'monthly_rent_currency' => $unit->rent_currency ?: 'USD',
            'security_deposit' => 0,
            'security_deposit_currency' => $unit->rent_currency ?: 'USD',
            'status' => RentalStatus::Active,
            'start_date' => $this->startDate,
        ]);
        $rental->setRelation('unit', $unit);

        // Create/reset tenant login account (which sets tenant_id and saves the rental)
        $accountResult = app(RoomAccountService::class)->createForRental($rental);

        $this->result = [
            'room_number' => $unit->room_number,
            'occupant_name' => $rental->occupant_name,
            'username' => $accountResult['username'],
            'password' => $accountResult['password'] ?? null,
            'created' => $accountResult['created'],
        ];

        $this->step = 'done';
    }

    public function reset_form(): void
    {
        $this->reset(['unitId', 'occupantName', 'occupantPhone', 'startDate', 'monthlyRent', 'result']);
        $this->startDate = now()->toDateString();
        $this->step = 'pick';
    }

    private function loadUnit(?int $id): ?Unit
    {
        if (! $id) return null;
        $propertyId = ActiveProperty::id();

        return Unit::query()
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->whereKey($id)
            ->first();
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();

        $vacantRooms = $propertyId
            ? Unit::query()
                ->with('activeRental')
                ->where('property_id', $propertyId)
                ->where('status', UnitStatus::Available->value)
                ->orderBy('room_number')
                ->get()
            : collect();

        $selectedUnit = $this->unitId ? $this->loadUnit($this->unitId) : null;

        return view('livewire.simple-add-tenant', compact('vacantRooms', 'selectedUnit'));
    }
}
