<?php

namespace App\Livewire\Mobile;

use App\Enums\ReadingType;
use App\Enums\UnitStatus;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\MeterUtilities;
use App\Services\RoomDetailsService;
use App\Services\RoomMeterReadingsService;
use App\Services\SubscriptionService;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m/rooms/{unit}/edit — a room's details (number, type, floor, rent, status,
 * occupants, notes) and its meter readings, on a phone. The phone version of
 * the panel's Edit room page (UnitResource\Pages\EditUnit); both save through
 * RoomDetailsService / RoomMeterReadingsService.
 *
 * Another landlord's room never gets here: the route binding runs through
 * LandlordScope and 404s.
 */
#[Layout('mobile.layout')]
class EditRoom extends Component
{
    public Unit $unit;

    public string $roomNumber = '';

    public string $floorNumber = '';

    public string $roomType = '';

    public string $rentAmount = '';

    public string $rentCurrency = 'USD';

    public int $status = UnitStatus::Available->value;

    public string $maxOccupants = '';

    public string $description = '';

    // ── Meter readings sheet ──
    public string $readingDate = '';

    public int $readingType = ReadingType::Actual->value;

    /** @var array<int|string, string> property_utility_id => reading */
    public array $meters = [];

    public function mount(Unit $unit): void
    {
        Gate::authorize('view', $unit);

        $this->unit = $unit;
        $this->fillForm();
        $this->resetReadings();
    }

    public function save(): void
    {
        if (! $this->canWrite()) {
            return;
        }

        Gate::authorize('update', $this->unit);

        $data = $this->validate([
            'roomNumber' => ['required', 'string', 'max:255'],
            'floorNumber' => ['nullable', 'string', 'max:255'],
            'roomType' => ['required', 'string', 'max:255'],
            'rentAmount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'rentCurrency' => ['required', Rule::in(['USD', 'KHR'])],
            'status' => ['required', Rule::enum(UnitStatus::class)],
            'maxOccupants' => ['nullable', 'integer', 'min:1', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'roomNumber' => __('Room number'),
            'floorNumber' => __('Floor'),
            'roomType' => __('Room type'),
            'rentAmount' => __('Rent amount'),
            'rentCurrency' => __('Currency'),
            'status' => __('Status'),
            'maxOccupants' => __('Max occupants'),
            'description' => __('Notes'),
        ]);

        app(RoomDetailsService::class)->update($this->unit, [
            'room_number' => trim($data['roomNumber']),
            'floor_number' => filled($data['floorNumber']) ? trim($data['floorNumber']) : null,
            'room_type' => trim($data['roomType']),
            'rent_amount' => $data['rentAmount'],
            'rent_currency' => $data['rentCurrency'],
            'status' => UnitStatus::from((int) $data['status']),
            'max_occupants' => filled($data['maxOccupants']) ? (int) $data['maxOccupants'] : 1,
            'description' => filled($data['description']) ? trim($data['description']) : null,
        ]);

        $this->fillForm();
        $this->dispatch('rw-m-toast', message: __('Room saved'));
    }

    public function saveReadings(): void
    {
        if (! $this->canWrite()) {
            return;
        }

        Gate::authorize('create', UtilityUsage::class);

        $this->validate([
            'readingDate' => ['required', 'date', 'before_or_equal:today'],
            'readingType' => ['required', Rule::enum(ReadingType::class)],
            'meters.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [
            'readingDate.before_or_equal' => __('The reading date cannot be in the future.'),
        ], [
            'readingDate' => __('Reading date'),
            'readingType' => __('Reading type'),
            'meters.*' => __('Reading'),
        ]);

        $saved = app(RoomMeterReadingsService::class)->save(
            $this->unit,
            $this->readingDate,
            $this->readingType,
            $this->meters,
        );

        if ($saved === 0) {
            $this->addError('meters', __('No readings entered.'));

            return;
        }

        $this->resetReadings();
        $this->dispatch('rw-m-sheet-close', name: 'room-readings');
        $this->dispatch('rw-m-toast', message: __(':count meter reading(s) saved for room :room', [
            'count' => $saved,
            'room' => $this->unit->room_number,
        ]));
    }

    public function delete(): void
    {
        if (! $this->canWrite()) {
            return;
        }

        Gate::authorize('delete', $this->unit);

        try {
            app(RoomDetailsService::class)->delete($this->unit);
        } catch (\DomainException $e) {
            $this->dispatch('rw-m-sheet-close', name: 'room-delete');
            $this->dispatch('rw-m-toast', message: $e->getMessage());

            return;
        }

        $this->redirect(route('mobile.rooms'), navigate: true);
    }

    /** A sheet was dismissed without saving: drop what was typed into it. */
    public function discardReadings(): void
    {
        $this->resetValidation(['readingDate', 'readingType', 'meters', 'meters.*']);
        $this->resetReadings();
    }

    public function render()
    {
        $user = auth()->user();
        $hasTenant = $this->unit->activeRental()->exists();

        return view('livewire.mobile.edit-room', [
            'statuses' => UnitStatus::options(),
            'readingTypes' => ReadingType::options(),
            'roomTypes' => Unit::query()
                ->where('property_id', $this->unit->property_id)
                ->whereNotNull('room_type')
                ->distinct()
                ->orderBy('room_type')
                ->pluck('room_type'),
            'meterUtilities' => $user?->can('create', UtilityUsage::class)
                ? MeterUtilities::forUnit($this->unit)
                : collect(),
            'hasTenant' => $hasTenant,
            'canDelete' => ! $hasTenant && (bool) $user?->can('delete', $this->unit),
        ])->title(__('Edit room'));
    }

    /** Read-only (unpaid) subscriptions can look but not change anything. */
    private function canWrite(): bool
    {
        if (SubscriptionService::canMutate(auth()->user())) {
            return true;
        }

        $this->dispatch('rw-m-toast', message: __('Write actions are disabled until payment is completed.'));

        return false;
    }

    private function fillForm(): void
    {
        $unit = $this->unit->refresh();

        $this->roomNumber = (string) $unit->room_number;
        $this->floorNumber = (string) $unit->floor_number;
        $this->roomType = (string) $unit->room_type;
        $this->rentAmount = $unit->rent_amount === null ? '' : static::plainNumber($unit->rent_amount);
        $this->rentCurrency = Money::normalize($unit->rent_currency);
        $this->status = ($unit->status ?? UnitStatus::Available)->value;
        $this->maxOccupants = (string) ($unit->max_occupants ?? '');
        $this->description = (string) $unit->description;
    }

    /** "300.00" → "300", "12.50" → "12.5": what a person would type. */
    private static function plainNumber(mixed $value): string
    {
        $value = number_format((float) $value, 2, '.', '');

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function resetReadings(): void
    {
        $this->readingDate = now()->toDateString();
        $this->readingType = ReadingType::Actual->value;
        $this->meters = [];
    }
}
