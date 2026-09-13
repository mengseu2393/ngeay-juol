<?php

namespace App\Livewire;

use App\Enums\ReadingType;
use App\Filament\Resources\UnitResource;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\MeterReadingResolver;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Simple mobile screen for landlords to record ongoing monthly meter readings.
 * Lists rooms for the active property; tapping one opens a reveal with one
 * numeric input per metered utility for that room's property.
 *
 * Unlike SimpleRoomList::submitUtilityReading() (a deliberately simplified
 * baseline-only "initial setup" flow — old_reading=null, amount=0), this screen
 * is for ongoing readings and mirrors UnitResource::meterReadingsAction()'s real
 * consumption math via MeterReadingResolver::baselineFor().
 */
class SimpleUtilityUsage extends Component
{
    public string $search = '';

    /** ID of the unit whose "record meter reading" reveal is open */
    public ?int $recordingUnitId = null;

    /** [property_utility_id => new reading] for the open reveal */
    public array $readingValues = [];

    public bool $readingSuccess = false;

    public function updatingSearch(): void
    {
        // no pagination to reset
    }

    public function openUtilityReading(int $unitId): void
    {
        $unit = $this->scopedUnit($unitId);

        if (! $unit) {
            return;
        }

        $this->recordingUnitId = $unitId;
        $this->readingValues = [];
        $this->readingSuccess = false;
        $this->resetErrorBag();
    }

    public function closeUtilityReading(): void
    {
        $this->recordingUnitId = null;
    }

    /**
     * Record today's meter readings for the open unit. Reading date is always
     * "today" — this is mobile quick-entry, unlike the desktop modal's date picker.
     */
    public function submitUtilityReading(): void
    {
        $unit = $this->scopedUnit($this->recordingUnitId);

        abort_unless($unit, 404);

        $this->validate([
            'readingValues.*' => 'nullable|numeric|min:0',
        ]);

        // Whitelist submitted utility ids against the ones actually belonging to
        // this unit's property — a tampered payload can't inject another
        // property's (or an inactive/flat) utility id. Same tamper-protection as
        // UnitResource::meterReadingsAction().
        $allowed = UnitResource::meterUtilitiesFor($unit)->keyBy('id');

        $entries = collect($this->readingValues)
            ->filter(fn ($v, $utilityId) => $v !== null && $v !== '' && $allowed->has((int) $utilityId));

        if ($entries->isEmpty()) {
            $this->addError('readingValues', __('Enter at least one meter reading.'));

            return;
        }

        $date = now()->toDateString();
        $rentalId = $unit->activeRental?->getKey();

        DB::transaction(function () use ($entries, $unit, $date, $rentalId): void {
            foreach ($entries as $utilityId => $value) {
                $utilityId = (int) $utilityId;
                $new = (float) $value;

                // Real consumption baseline (previous reading / meter install
                // index), not the zero-usage baseline SimpleRoomList uses for a
                // room's very first reading.
                $baseline = app(MeterReadingResolver::class)
                    ->baselineFor($unit->getKey(), $utilityId, $date, $new);

                // Idempotent per (unit, utility, date): re-submitting the same
                // day updates the row instead of stacking duplicates. Matched
                // via whereDate() rather than an exact `reading_date =` equality
                // (as UtilityUsage::updateOrCreate() would do) because the
                // date-cast column round-trips through a full datetime string
                // on write, which only some DB drivers normalize back to a bare
                // date on read — whereDate() is the same comparison
                // MeterReadingResolver already uses and works everywhere.
                $usage = UtilityUsage::query()
                    ->where('unit_id', $unit->getKey())
                    ->where('property_utility_id', $utilityId)
                    ->whereDate('reading_date', $date)
                    ->first() ?? new UtilityUsage([
                        'unit_id' => $unit->getKey(),
                        'property_utility_id' => $utilityId,
                        'reading_date' => $date,
                    ]);

                $usage->fill([
                    'rental_id' => $rentalId,
                    'reading_type' => ReadingType::Actual,
                    'old_reading' => $baseline['old'],
                    'new_reading' => $new,
                    'amount_used' => $baseline['amount'],
                    'recorded_by_id' => auth()->id(),
                ])->save();
            }
        });

        $this->readingSuccess = true;
        $this->recordingUnitId = null;
    }

    /** The unit, scoped to the active property — null if missing/foreign. */
    private function scopedUnit(?int $unitId): ?Unit
    {
        if (! $unitId) {
            return null;
        }

        return Unit::query()
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereKey($unitId)
            ->first();
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();

        $rooms = $propertyId
            ? Unit::query()
                ->where('property_id', $propertyId)
                ->when($this->search !== '', function ($q) {
                    $q->where('room_number', 'like', '%'.trim($this->search).'%');
                })
                ->orderBy('room_number')
                ->get()
            : collect();

        $recordingUnit = $this->recordingUnitId ? $this->scopedUnit($this->recordingUnitId) : null;

        $meteredUtilities = $recordingUnit
            ? UnitResource::meterUtilitiesFor($recordingUnit)
            : collect();

        return view('livewire.simple-utility-usage', [
            'rooms' => $rooms,
            'meteredUtilities' => $meteredUtilities,
        ]);
    }
}
