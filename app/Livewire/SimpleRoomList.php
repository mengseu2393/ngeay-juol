<?php

namespace App\Livewire;

use App\Enums\ReadingType;
use App\Enums\UnitStatus;
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

    /** Filter: 'all' | 'available' (vacant) | 'occupied' | 'maintenance' */
    public string $filter = 'all';

    protected $queryString = ['filter'];

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

    /** ID of the unit whose "edit room price" popup is open */
    public ?int $editingPriceUnitId = null;

    public ?string $priceValue = null;

    public bool $priceSuccess = false;

    /** ID of the rental being edited in the "Edit tenant" popup */
    public ?int $editingRentalId = null;

    protected $listeners = [
        'tenant-updated' => 'handleTenantUpdated',
    ];

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
    public function resetTenantLogin(int $rentalId): array
    {
        $rental = $this->scopedRental($rentalId);

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        $result = app(RoomAccountService::class)->createForRental($rental);

        $this->newUsername = $result['username'];
        $this->newPassword = $result['password'];

        // Returned to the client-side popup (Alpine) that shows it once.
        return ['username' => $result['username'], 'password' => $result['password']];
    }

    public function editTenant(int $rentalId): void
    {
        if (! $this->scopedRental($rentalId)) {
            return;
        }

        // Opened from inside the "view tenant" popup — close it first so the
        // two fixed-overlay popups don't stack (same as SimpleTenantList).
        $this->viewingRentalId = null;
        $this->editingRentalId = $rentalId;
    }

    public function closeEditTenant(): void
    {
        $this->editingRentalId = null;
    }

    public function handleTenantUpdated(): void
    {
        $this->editingRentalId = null;
    }

    public function openEditPrice(int $unitId): void
    {
        $unit = $this->scopedUnit($unitId);

        if (! $unit) {
            return;
        }

        $this->editingPriceUnitId = $unitId;
        $this->priceValue = $unit->rent_amount !== null ? (string) $unit->rent_amount : null;
        $this->priceSuccess = false;
    }

    public function closeEditPrice(): void
    {
        $this->editingPriceUnitId = null;
        $this->priceValue = null;
    }

    /**
     * One-shot save for the client-side (Alpine) price popup: the popup opens
     * instantly with the card's data, so this save is the only round-trip.
     */
    public function submitEditPriceFor(int $unitId, string $price): void
    {
        $this->editingPriceUnitId = $unitId;
        $this->priceValue = $price;

        $this->submitEditPrice();
    }

    /**
     * Updates the unit's listed rent and, when a tenancy is active, that
     * tenancy's monthly_rent too — otherwise the card (which shows the
     * active rental's rent) and the next invoice wouldn't reflect the change.
     */
    public function submitEditPrice(): void
    {
        $unit = $this->scopedUnit($this->editingPriceUnitId);

        abort_unless($unit, 404);
        abort_unless(Auth::user()?->can('update', $unit), 403);

        $this->validate([
            'priceValue' => 'required|numeric|min:0',
        ]);

        $unit->update(['rent_amount' => $this->priceValue]);

        if ($unit->activeRental) {
            $unit->activeRental->update(['monthly_rent' => $this->priceValue]);
        }

        $this->priceSuccess = true;
        $this->editingPriceUnitId = null;
        $this->priceValue = null;
        $this->dispatch('room-price-saved');
    }

    /** The unit, scoped to the active property — null if missing/foreign. */
    private function scopedUnit(?int $unitId): ?Unit
    {
        if (! $unitId) {
            return null;
        }

        return Unit::query()
            ->with('activeRental')
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereKey($unitId)
            ->first();
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
     * One-shot save for the client-side (Alpine) reading popup — see
     * submitEditPriceFor(). $readings is [property_utility_id => reading].
     */
    public function submitUtilityReadingFor(int $unitId, array $readings): void
    {
        $this->settingReadingUnitId = $unitId;
        $this->readingValues = $readings;
        $this->readingSuccess = false;

        $this->submitUtilityReading();
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

        // Only this property's metered utilities count — anything else keyed
        // into readingValues (a stale form, a tampered request) is ignored.
        $allowed = PropertyUtility::query()
            ->where('property_id', $unit->property_id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $entries = collect($this->readingValues)
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && in_array((int) $k, $allowed, true));

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
        $this->readingValues = [];
        $this->dispatch('room-reading-saved');
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();

        $rooms = $propertyId
            ? Unit::query()
                ->with(['activeRental.tenant', 'activeRental.media', 'property'])
                ->withCount('utilityUsages')
                ->where('property_id', $propertyId)
                ->when($this->filter === 'available', fn ($q) => $q->where('status', UnitStatus::Available))
                ->when($this->filter === 'occupied', fn ($q) => $q->where('status', UnitStatus::Occupied))
                ->when($this->filter === 'maintenance', fn ($q) => $q->whereIn('status', [UnitStatus::Maintenance, UnitStatus::Unavailable]))
                ->when($this->search !== '', function ($q) {
                    $s = '%'.trim($this->search).'%';
                    // Grouped so the status filter above still applies to every OR branch.
                    $q->where(fn ($sq) => $sq->where('room_number', 'like', $s)
                        ->orWhereHas('activeRental', fn ($rq) => $rq->where('occupant_name', 'like', $s)
                            ->orWhereHas('tenant', fn ($tq) => $tq->where('name', 'like', $s))
                        ));
                })
                ->orderBy('room_number')
                ->get()
            : collect();

        // Always loaded: the reading popup is rendered once, client-side, and
        // reused for every room (metered utilities are property-wide).
        $meteredUtilities = $propertyId
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
            'editingPriceUnit' => $this->scopedUnit($this->editingPriceUnitId),
        ]);
    }
}
