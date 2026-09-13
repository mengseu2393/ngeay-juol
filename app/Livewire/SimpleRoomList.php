<?php

namespace App\Livewire;

use App\Enums\ReadingType;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Simple room status list for mobile/PWA.
 * Shows all rooms for the active property with status badge and quick-action links.
 */
class SimpleRoomList extends Component
{
    public string $search = '';

    /** ID of the unit whose "set utility reading" popup is open */
    public ?int $settingReadingUnitId = null;

    /** [property_utility_id => baseline reading] for the open popup */
    public array $readingValues = [];

    public bool $readingSuccess = false;

    /** ID of the rental whose tenant-detail popup is open */
    public ?int $viewingRentalId = null;

    /** One-time login credentials just (re)generated — shown once, never persisted. */
    public ?string $newUsername = null;

    public ?string $newPassword = null;

    public function updatingSearch(): void
    {
        // no pagination to reset
    }

    public function openUtilityReading(int $unitId): void
    {
        $this->settingReadingUnitId = $unitId;
        $this->readingValues = [];
        $this->readingSuccess = false;
    }

    public function closeUtilityReading(): void
    {
        $this->settingReadingUnitId = null;
    }

    public function viewTenant(int $rentalId): void
    {
        $rental = $this->scopedRental($rentalId);

        if (! $rental) {
            return;
        }

        $this->viewingRentalId = $rentalId;
        $this->newUsername = null;
        $this->newPassword = null;
    }

    public function closeTenantView(): void
    {
        $this->viewingRentalId = null;
        $this->newUsername = null;
        $this->newPassword = null;
    }

    /**
     * Passwords are hashed at rest and can never be retrieved once set — this
     * mints a fresh one (or a first one, if the tenancy is still on its unit's
     * shared room account) and surfaces it once, exactly like
     * RentalResource\Actions\TenantLogin does on the desktop panel.
     */
    public function resetTenantLogin(int $rentalId): void
    {
        $rental = $this->scopedRental($rentalId);

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        $result = app(RoomAccountService::class)->createForRental($rental);

        $this->newUsername = $result['username'];
        $this->newPassword = $result['password'];
    }

    /** The rental, scoped to the active property — null if missing/foreign. */
    private function scopedRental(?int $rentalId): ?Rental
    {
        if (! $rentalId) {
            return null;
        }

        return Rental::query()
            ->with(['tenant', 'unit.property'])
            ->when(ActiveProperty::id(), fn ($q) => $q->whereHas('unit', fn ($uq) => $uq->where('property_id', ActiveProperty::id())))
            ->whereKey($rentalId)
            ->first();
    }

    /**
     * One row per metered utility the landlord filled in — a flat charge
     * needs no meter, and a blank field is skipped rather than saved as a
     * zero reading. old_reading is left null (there is no "previous" yet)
     * and amount_used stays 0: this only stamps the starting point billing
     * will measure future consumption against.
     */
    public function submitUtilityReading(): void
    {
        $unit = Unit::query()
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereKey($this->settingReadingUnitId)
            ->firstOrFail();

        $this->validate([
            'readingValues.*' => 'nullable|numeric|min:0',
        ]);

        $entries = collect($this->readingValues)->filter(fn ($v) => $v !== null && $v !== '');

        if ($entries->isEmpty()) {
            $this->addError('readingValues', __('Enter at least one meter reading.'));

            return;
        }

        foreach ($entries as $propertyUtilityId => $reading) {
            UtilityUsage::create([
                'property_utility_id' => $propertyUtilityId,
                'unit_id' => $unit->id,
                'rental_id' => $unit->activeRental?->id,
                'recorded_by_id' => Auth::id(),
                'reading_type' => ReadingType::Actual,
                'reading_date' => now(),
                'old_reading' => null,
                'new_reading' => $reading,
                'amount_used' => 0,
            ]);
        }

        $this->readingSuccess = true;
        $this->settingReadingUnitId = null;
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();

        $rooms = $propertyId
            ? Unit::query()
                ->with(['activeRental.tenant'])
                ->withCount('utilityUsages')
                ->where('property_id', $propertyId)
                ->when($this->search !== '', function ($q) {
                    $s = '%'.trim($this->search).'%';
                    $q->where('room_number', 'like', $s)
                        ->orWhereHas('activeRental', fn ($rq) => $rq->where('occupant_name', 'like', $s)
                            ->orWhereHas('tenant', fn ($tq) => $tq->where('name', 'like', $s))
                        );
                })
                ->orderBy('room_number')
                ->get()
            : collect();

        $meteredUtilities = $this->settingReadingUnitId && $propertyId
            ? PropertyUtility::query()
                ->where('property_id', $propertyId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (PropertyUtility $u) => $u->requiresReading())
            : collect();

        return view('livewire.simple-room-list', [
            'rooms' => $rooms,
            'meteredUtilities' => $meteredUtilities,
            'viewingRental' => $this->scopedRental($this->viewingRentalId),
        ]);
    }
}
