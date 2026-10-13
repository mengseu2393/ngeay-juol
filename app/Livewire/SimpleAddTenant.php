<?php

namespace App\Livewire;

use App\Enums\UnitStatus;
use App\Livewire\Concerns\StartsTenancy;
use App\Models\Unit;
use App\Services\MeterUtilities;
use App\Support\ActiveProperty;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Simple add-tenant flow for mobile/PWA.
 * Step 1: pick vacant room → Step 2: enter tenant info → Step 3: confirm result.
 * Covers the occupant/tenancy fields a landlord fills in on move-in day (name,
 * phone, ID card, gender, DOB, nationality, workplace, address, emergency
 * contact, deposit) plus ID card photos, attached to the Rental's own
 * `id_cards` media collection — the same one RentalResource's desktop form
 * uses, so a photo taken here shows up on the desktop Full Mode view too.
 * Guarantor details stay deferred to Full Mode. Uses existing rental/tenancy
 * rules and RoomAccountService. The form and its submit are
 * {@see StartsTenancy} (shared with the /m shell); the room picker is this
 * screen's own.
 */
class SimpleAddTenant extends Component
{
    use StartsTenancy;
    use WithFileUploads;

    /** current wizard step: 'pick' | 'details' | 'done' */
    public string $step = 'pick';

    public ?int $unitId = null;

    /** Sibling Simple Mode screens stay mounted on one page; re-render when they change shared data. */
    protected $listeners = ['tenancy-ended' => '$refresh'];

    public function mount(?int $unitId = null): void
    {
        $this->startDate = now()->toDateString();

        // An explicit unit (embedded in the property's Units & agreements tab) wins over the ?unit_id= deep link.
        $unitId = $unitId ?? (int) request()->query('unit_id');
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
        $this->occupantIdCard = '';
        $this->occupantGender = '';
        $this->occupantDob = '';
        $this->occupantNationality = '';
        $this->occupantWorkplace = '';
        $this->occupantAddress = '';
        $this->emergencyContactName = '';
        $this->emergencyContactPhone = '';
        $this->emergencyContactRelationship = '';
        $this->monthlyRent = '';
        $this->securityDeposit = '';
        $this->idCardPhotos = [];
        $this->moveInReadings = [];
        $this->result = null;
    }

    public function reset_form(): void
    {
        $this->reset([
            'unitId', 'occupantName', 'occupantPhone', 'occupantIdCard', 'occupantGender',
            'occupantDob', 'occupantNationality', 'occupantWorkplace', 'occupantAddress',
            'emergencyContactName', 'emergencyContactPhone', 'emergencyContactRelationship',
            'startDate', 'monthlyRent', 'securityDeposit', 'idCardPhotos', 'moveInReadings', 'result',
        ]);
        $this->startDate = now()->toDateString();
        $this->step = 'pick';
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

        $meteredUtilities = $selectedUnit ? MeterUtilities::forUnit($selectedUnit) : collect();

        return view('livewire.simple-add-tenant', compact('vacantRooms', 'selectedUnit', 'meteredUtilities'));
    }
}
