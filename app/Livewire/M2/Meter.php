<?php

namespace App\Livewire\M2;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\MeterReadingResolver;
use App\Services\UtilityReadingCorrectionService;
use App\Services\UtilityReadingResetService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/rooms/{unit}/meter/{utility} — sketch 2's Electricity / Water: the room
 * and the meter, Begin (the previous reading), End (today's reading), what that
 * used, Reset (a new starting point after the meter was replaced or rolled
 * over) and the price. Saving goes through the same MeterReadingResolver and
 * UtilityReadingResetService the other screens use, so the consumption maths and
 * the billing baseline can't drift; one reading per room + utility + day.
 */
#[Layout('m2.layout')]
class Meter extends Component
{
    public Unit $unit;

    public PropertyUtility $utility;

    /** End — today's reading. */
    public string $reading = '';

    /** Reset — the new starting reading, '' while the reset form is closed. */
    public string $resetTo = '';

    public bool $resetting = false;

    /** Which change is waiting for a "yes, really": 'save' | 'reset' | 'edit' | null. */
    public ?string $confirming = null;

    /** Reset only — the owner ticked that they know what a reset does. */
    public bool $understood = false;

    /** Correct — the past reading being fixed, and its new number / date. */
    public ?int $editingId = null;

    public string $editReading = '';

    public string $editDate = '';

    public function mount(Unit $unit, PropertyUtility $utility): void
    {
        // A meter of another property — or a flat charge, which has no meter — isn't this room's.
        abort_unless($utility->property_id === $unit->property_id && $utility->billing_type !== BillingType::Flat, 404);

        $this->unit = $unit;
        $this->utility = $utility;
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('create', UtilityUsage::class), 403);

        $this->validate(['reading' => ['required', 'numeric', 'min:0', 'max:999999999']], [
            'reading.required' => __('Enter the meter reading.'),
            'reading.numeric' => __('Enter a valid meter reading.'),
            'reading.min' => __('Enter a valid meter reading.'),
            'reading.max' => __('Enter a valid meter reading.'),
        ]);

        $this->confirming = 'save';
    }

    public function confirmSave(): void
    {
        abort_unless(Auth::user()?->can('create', UtilityUsage::class), 403);

        $this->validate(['reading' => ['required', 'numeric', 'min:0', 'max:999999999']]);

        $date = now()->toDateString();
        $new = (float) $this->reading;

        DB::transaction(function () use ($date, $new): void {
            $baseline = app(MeterReadingResolver::class)->baselineFor($this->unit->id, $this->utility->id, $date, $new);

            // Re-saving the same day updates that day's row instead of stacking another.
            $usage = UtilityUsage::query()
                ->where('unit_id', $this->unit->id)
                ->where('property_utility_id', $this->utility->id)
                ->whereDate('reading_date', $date)
                ->first() ?? new UtilityUsage([
                    'unit_id' => $this->unit->id,
                    'property_utility_id' => $this->utility->id,
                    'reading_date' => $date,
                ]);

            $usage->fill([
                'rental_id' => $this->unit->activeRental?->getKey(),
                'reading_type' => ReadingType::Actual,
                'old_reading' => $baseline['old'],
                'new_reading' => $new,
                'amount_used' => $baseline['amount'],
                'recorded_by_id' => Auth::id(),
            ])->save();
        });

        $this->reading = '';
        $this->confirming = null;
        $this->dispatch('rw-m-toast', message: __('Meter reading saved.'));
    }

    /** Back from any "are you sure?" step without changing anything. */
    public function backFromConfirm(): void
    {
        $this->confirming = null;
        $this->understood = false;
    }

    public function openReset(): void
    {
        $this->resetting = true;
        $this->confirming = null;
        $this->understood = false;
        $this->resetTo = '0';
    }

    public function cancelReset(): void
    {
        $this->resetting = false;
        $this->confirming = null;
        $this->understood = false;
        $this->resetTo = '';
        $this->resetErrorBag();
    }

    public function confirmReset(): void
    {
        abort_unless(Auth::user()?->can('create', UtilityUsage::class), 403);

        $this->validate(['resetTo' => ['required', 'numeric', 'min:0', 'max:999999999']], [
            'resetTo.required' => __('Enter the new starting reading.'),
            'resetTo.numeric' => __('Enter a valid meter reading.'),
            'resetTo.min' => __('Enter a valid meter reading.'),
            'resetTo.max' => __('Enter a valid meter reading.'),
        ]);

        $this->confirming = 'reset';
        $this->understood = false;
    }

    public function applyReset(): void
    {
        abort_unless(Auth::user()?->can('create', UtilityUsage::class), 403);

        $this->validate(['resetTo' => ['required', 'numeric', 'min:0', 'max:999999999']]);

        if (! $this->understood) {
            $this->addError('understood', __('Tick the box to confirm.'));

            return;
        }

        $today = UtilityUsage::query()
            ->where('unit_id', $this->unit->id)
            ->where('property_utility_id', $this->utility->id)
            ->whereDate('reading_date', now()->toDateString())
            ->where('is_reset', false)
            ->exists();

        if ($today) {
            $this->addError('date', __('A reading is already saved today. Change it in the history below, or reset the meter tomorrow.'));

            return;
        }

        try {
            app(UtilityReadingResetService::class)->reset($this->unit, $this->utility->id, (float) $this->resetTo, now()->toDateString());
        } catch (ValidationException $e) {
            // Shown on the confirm card itself — before, a refusal left the card as it was and the tap looked dead.
            $this->addError('date', collect($e->errors())->flatten()->first());

            return;
        }

        $this->resetting = false;
        $this->confirming = null;
        $this->understood = false;
        $this->resetTo = '';
        $this->dispatch('rw-m-toast', message: __('Meter reset. The next reading starts from here.'));
    }

    /** Correct — open a past reading to fix its number (or date), e.g. back to last month's. */
    public function edit(int $usageId): void
    {
        $usage = $this->scopedUsage($usageId);
        abort_unless(Auth::user()?->can('update', $usage), 403);

        if (! app(UtilityReadingCorrectionService::class)->isEditable($usage)) {
            return;
        }

        $this->resetErrorBag();
        $this->confirming = null;
        $this->editingId = $usage->getKey();
        $this->editReading = rtrim(rtrim(number_format((float) $usage->new_reading, 3, '.', ''), '0'), '.');
        $this->editDate = $usage->reading_date->toDateString();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->confirming = null;
        $this->resetErrorBag();
    }

    /** Re-chains every later reading and refuses anything already billed (see the service). */
    public function saveEdit(): void
    {
        $usage = $this->scopedUsage((int) $this->editingId);
        abort_unless(Auth::user()?->can('update', $usage), 403);

        $this->validate([
            'editReading' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'editDate' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $this->confirming = 'edit';
    }

    public function applyEdit(): void
    {
        $usage = $this->scopedUsage((int) $this->editingId);
        abort_unless(Auth::user()?->can('update', $usage), 403);

        $this->validate([
            'editReading' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'editDate' => ['required', 'date', 'before_or_equal:today'],
        ]);

        try {
            app(UtilityReadingCorrectionService::class)->apply($usage, (float) $this->editReading, $this->editDate, null, true);
        } catch (ValidationException $e) {
            $this->addError('editReading', collect($e->errors())->flatten()->first());
            $this->confirming = null;

            return;
        }

        $this->editingId = null;
        $this->confirming = null;
        $this->dispatch('rw-m-toast', message: __('Meter reading saved.'));
    }

    private function scopedUsage(int $id): UtilityUsage
    {
        return UtilityUsage::query()
            ->where('unit_id', $this->unit->id)
            ->where('property_utility_id', $this->utility->id)
            ->findOrFail($id);
    }

    public function render()
    {
        $previous = app(MeterReadingResolver::class)->previous($this->unit->id, $this->utility->id);

        // Live preview of what the typed End reading would use (same rule as saving).
        $used = null;
        if ($this->reading !== '' && is_numeric($this->reading) && (float) $this->reading >= 0) {
            $used = app(MeterReadingResolver::class)->consumption((float) $previous['previous'], (float) $this->reading, $previous['meter']);
        }

        $history = UtilityUsage::query()
            ->where('unit_id', $this->unit->id)
            ->where('property_utility_id', $this->utility->id)
            ->orderByDesc('reading_date')->orderByDesc('id')
            ->limit(6)
            ->get();

        return view('livewire.m2.meter', [
            'unit' => $this->unit,
            'utility' => $this->utility,
            'begin' => $previous['previous'],
            'hasPrevious' => $previous['source'] !== 'none',
            'used' => $used,
            'history' => $history,
            'service' => app(UtilityReadingCorrectionService::class),
        ])->title(__($this->utility->name));
    }
}
