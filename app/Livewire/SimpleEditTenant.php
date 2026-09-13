<?php

namespace App\Livewire;

use App\Models\Rental;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Simple edit-tenant flow for mobile/PWA — edits occupant/tenancy details on
 * an EXISTING active Rental. Does not create rentals and does not touch
 * unit_id/start_date/status/tenant login — that's SimpleAddTenant's job.
 * Flat single-step form (no wizard), meant to be embedded inside a shared
 * popup shell wired up elsewhere.
 */
class SimpleEditTenant extends Component
{
    use WithFileUploads;

    public ?int $rentalId = null;

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

    public string $monthlyRent = '';

    public string $securityDeposit = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $idCardPhotos = [];

    public bool $saved = false;

    public function mount(int $rentalId): void
    {
        $this->rentalId = $rentalId;

        $rental = $this->scopedRental();

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        $this->occupantName = (string) $rental->occupant_name;
        $this->occupantPhone = (string) $rental->occupant_phone;
        $this->occupantIdCard = (string) $rental->occupant_id_card;
        $this->occupantGender = (string) $rental->occupant_gender;
        $this->occupantDob = $rental->occupant_dob ? $rental->occupant_dob->toDateString() : '';
        $this->occupantNationality = (string) $rental->occupant_nationality;
        $this->occupantWorkplace = (string) $rental->occupant_workplace;
        $this->occupantAddress = (string) $rental->occupant_address;
        $this->emergencyContactName = (string) $rental->emergency_contact_name;
        $this->emergencyContactPhone = (string) $rental->emergency_contact_phone;
        $this->emergencyContactRelationship = (string) $rental->emergency_contact_relationship;
        $this->monthlyRent = (string) $rental->monthly_rent;
        $this->securityDeposit = (string) $rental->security_deposit;
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
            'monthlyRent' => 'required|numeric|min:0',
            'securityDeposit' => 'nullable|numeric|min:0',
            'idCardPhotos' => 'nullable|array|max:2',
            'idCardPhotos.*' => 'image|max:5120', // 5MB — front/back of an ID card
        ]);

        $rental = $this->scopedRental();

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        $rental->update([
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
            'security_deposit' => $this->securityDeposit !== '' ? (float) $this->securityDeposit : 0,
        ]);

        foreach ($this->idCardPhotos as $photo) {
            $rental->addMedia($photo->getRealPath())
                ->usingFileName($photo->getClientOriginalName())
                ->toMediaCollection('id_cards');
        }

        $this->idCardPhotos = [];
        $this->saved = true;

        $this->dispatch('tenant-updated');
    }

    /** The rental, scoped to the active property — null if missing/foreign. */
    private function scopedRental(): ?Rental
    {
        if (! $this->rentalId) {
            return null;
        }

        return Rental::query()
            ->when(ActiveProperty::id(), fn ($q) => $q->whereHas('unit', fn ($uq) => $uq->where('property_id', ActiveProperty::id())))
            ->whereKey($this->rentalId)
            ->first();
    }

    public function render()
    {
        return view('livewire.simple-edit-tenant');
    }
}
