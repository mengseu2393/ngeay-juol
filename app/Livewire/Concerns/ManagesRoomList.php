<?php

namespace App\Livewire\Concerns;

use App\Enums\ReadingType;
use App\Livewire\Mobile\Rooms;
use App\Livewire\SimpleRoomList;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The room list's own logic — the rooms of the active property, the room-price
 * edit and the first (baseline) meter reading — shared by Simple Mode's Rooms
 * screen ({@see SimpleRoomList}) and the /m shell's ({@see Rooms}) so the
 * two can never drift. Each screen brings its own view; a screen that wants to
 * react to a save beyond the `room-price-saved` / `room-reading-saved` browser
 * events overrides {@see roomPriceSaved()} / {@see roomReadingSaved()}.
 */
trait ManagesRoomList
{
    /**
     * Initial filter pill only ('all' | 'available' | 'occupied' | 'maintenance'), read from
     * ?filter= so deep links keep working. Every room is always rendered and the pills +
     * search filter them in the browser — no request per tap.
     */
    public string $filter = 'all';

    /** ID of the unit whose "set utility reading" popup is open */
    public ?int $settingReadingUnitId = null;

    /** [property_utility_id => baseline reading] for the open popup */
    public array $readingValues = [];

    public bool $readingSuccess = false;

    /** ID of the unit whose "edit room price" popup is open */
    public ?int $editingPriceUnitId = null;

    public ?string $priceValue = null;

    public bool $priceSuccess = false;

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
        $this->roomPriceSaved();
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
        $this->roomReadingSaved();
    }

    /** Hook: the room price was just saved. */
    protected function roomPriceSaved(): void {}

    /** Hook: a room's first meter reading was just saved. */
    protected function roomReadingSaved(): void {}

    /** The unit, scoped to the active property — null if missing/foreign. */
    protected function scopedUnit(?int $unitId): ?Unit
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

    /**
     * Every room of the active property (none without one), plus its metered
     * utilities — always loaded, since the reading popup is rendered once,
     * client-side, and reused for every room (metered utilities are property-wide).
     *
     * @param  array<int, string>  $with  extra eager loads a screen's cards need
     * @return array{rooms: Collection<int, Unit>, meteredUtilities: Collection<int, PropertyUtility>}
     */
    protected function roomListData(array $with = []): array
    {
        $propertyId = ActiveProperty::id();

        $rooms = $propertyId
            ? Unit::query()
                ->with(array_merge(['activeRental.tenant', 'property'], $with))
                ->withCount('utilityUsages')
                ->where('property_id', $propertyId)
                ->orderBy('room_number')
                ->get()
            : collect();

        $meteredUtilities = $propertyId
            ? PropertyUtility::query()
                ->where('property_id', $propertyId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (PropertyUtility $u) => $u->requiresReading())
            : collect();

        return ['rooms' => $rooms, 'meteredUtilities' => $meteredUtilities];
    }
}
