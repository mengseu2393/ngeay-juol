<?php

namespace App\Livewire;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Rental;
use App\Models\Unit;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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
 * rules and RoomAccountService.
 */
class SimpleAddTenant extends Component
{
    use WithFileUploads;

    /** current wizard step: 'pick' | 'details' | 'done' */
    public string $step = 'pick';

    public ?int $unitId = null;

    public string $occupantName = '';

    public string $occupantPhone = '';

    public string $occupantIdCard = '';

    public string $occupantGender = '';

    public string $occupantDob = '';

    public string $occupantNationality = '';

    public string $occupantWorkplace = '';

    public string $occupantAddress = '';

    public string $emergencyContactName = '';

    public string $emergencyContactPhone = '';

    public string $emergencyContactRelationship = '';

    public string $startDate = '';

    public string $monthlyRent = '';

    public string $securityDeposit = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $idCardPhotos = [];

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
        $this->result = null;
    }

    public function removeIdCardPhoto(int $index): void
    {
        unset($this->idCardPhotos[$index]);
        $this->idCardPhotos = array_values($this->idCardPhotos);
    }

    public function submit(): void
    {
        $this->validate([
            'occupantName' => 'required|string|max:255',
            'occupantPhone' => 'nullable|string|max:50',
            'occupantIdCard' => 'nullable|string|max:255',
            'occupantGender' => 'nullable|in:male,female,other',
            'occupantDob' => 'nullable|date',
            'occupantNationality' => 'nullable|string|max:255',
            'occupantWorkplace' => 'nullable|string|max:255',
            'occupantAddress' => 'nullable|string|max:1000',
            'emergencyContactName' => 'nullable|string|max:255',
            'emergencyContactPhone' => 'nullable|string|max:50',
            'emergencyContactRelationship' => 'nullable|string|max:255',
            'startDate' => 'required|date',
            'monthlyRent' => 'required|numeric|min:0',
            'securityDeposit' => 'nullable|numeric|min:0',
            'unitId' => 'required|integer',
            'idCardPhotos' => 'nullable|array|max:2',
            'idCardPhotos.*' => 'image|max:5120', // 5MB — front/back of an ID card
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
            'occupant_id_card' => trim($this->occupantIdCard) ?: null,
            'occupant_gender' => $this->occupantGender ?: null,
            'occupant_dob' => $this->occupantDob ?: null,
            'occupant_nationality' => trim($this->occupantNationality) ?: null,
            'occupant_workplace' => trim($this->occupantWorkplace) ?: null,
            'occupant_address' => trim($this->occupantAddress) ?: null,
            'emergency_contact_name' => trim($this->emergencyContactName) ?: null,
            'emergency_contact_phone' => trim($this->emergencyContactPhone) ?: null,
            'emergency_contact_relationship' => trim($this->emergencyContactRelationship) ?: null,
            'monthly_rent' => (float) $this->monthlyRent,
            'monthly_rent_currency' => $unit->rent_currency ?: 'USD',
            'security_deposit' => $this->securityDeposit !== '' ? (float) $this->securityDeposit : 0,
            'security_deposit_currency' => $unit->rent_currency ?: 'USD',
            'status' => RentalStatus::Active,
            'start_date' => $this->startDate,
        ]);
        $rental->setRelation('unit', $unit);

        // Create/reset tenant login account (which sets tenant_id and saves the rental)
        $accountResult = app(RoomAccountService::class)->createForRental($rental);

        foreach ($this->idCardPhotos as $photo) {
            $rental->addMedia($photo->getRealPath())
                ->usingFileName($photo->getClientOriginalName())
                ->toMediaCollection('id_cards');
        }

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
        $this->reset([
            'unitId', 'occupantName', 'occupantPhone', 'occupantIdCard', 'occupantGender',
            'occupantDob', 'occupantNationality', 'occupantWorkplace', 'occupantAddress',
            'emergencyContactName', 'emergencyContactPhone', 'emergencyContactRelationship',
            'startDate', 'monthlyRent', 'securityDeposit', 'idCardPhotos', 'result',
        ]);
        $this->startDate = now()->toDateString();
        $this->step = 'pick';
    }

    private function loadUnit(?int $id): ?Unit
    {
        if (! $id) {
            return null;
        }
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
