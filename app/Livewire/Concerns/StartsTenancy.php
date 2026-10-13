<?php

namespace App\Livewire\Concerns;

use App\Livewire\Mobile\AddTenant;
use App\Livewire\SimpleAddTenant;
use App\Models\Unit;
use App\Services\StartTenancyService;
use App\Support\ActiveProperty;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The move-in form — occupant, emergency contact, ID card photos, rent,
 * deposit and move-in meter readings — and its submit, shared by Simple Mode's
 * add-tenant flow ({@see SimpleAddTenant}) and the /m shell's
 * ({@see AddTenant}). The rules are StartTenancyService's.
 *
 * The component brings `$unitId` (?int) and `$step` (string, 'done' once the
 * tenant is in) itself, plus Livewire\WithFileUploads for the photos.
 */
trait StartsTenancy
{
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

    /**
     * Optional move-in meter readings, [property_utility_id => reading]. They
     * become the tenant's starting point, so billing measures from them.
     *
     * @var array<int|string, mixed>
     */
    public array $moveInReadings = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $idCardPhotos = [];

    /** Result from creation */
    public ?array $result = null;

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
            'moveInReadings.*' => 'nullable|numeric|min:0|max:999999999',
            'idCardPhotos' => 'nullable|array|max:2',
            'idCardPhotos.*' => 'image|max:5120', // 5MB — front/back of an ID card
        ]);

        $unit = $this->loadUnit($this->unitId);

        if (! $unit) {
            $this->addError('unitId', __('Room not found or not available.'));

            return;
        }

        try {
            $started = app(StartTenancyService::class)->start(
                $unit,
                [
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
                ],
                $this->startDate,
                (float) $this->monthlyRent,
                $this->securityDeposit !== '' ? (float) $this->securityDeposit : 0,
                $this->moveInReadings,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $rental = $started['rental'];
        $accountResult = $started['account'];

        foreach ($this->idCardPhotos as $photo) {
            $rental->addMedia($photo->getRealPath())
                ->usingFileName($photo->getClientOriginalName())
                ->toMediaCollection('id_cards');
        }

        $this->result = [
            'rental_id' => $rental->getKey(),
            'room_number' => $unit->room_number,
            'occupant_name' => $rental->occupant_name,
            'username' => $accountResult['username'],
            'password' => $accountResult['password'] ?? null,
            'created' => $accountResult['created'],
        ];

        $this->step = 'done';

        // The Rooms/Tenants screens are already mounted on the same page and
        // would otherwise still show this room as vacant.
        $this->dispatch('tenancy-started');
    }

    /** The room being let, scoped to the active property — null if missing/foreign. */
    protected function loadUnit(?int $id): ?Unit
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
}
