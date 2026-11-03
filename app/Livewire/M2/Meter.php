<?php

namespace App\Livewire\M2;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\MeterReadingResolver;
use App\Services\UtilityReadingResetService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public ?string $saved = null;

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
        $this->saved = __('Meter reading saved.');
    }

    public function openReset(): void
    {
        $this->resetting = true;
        $this->resetTo = '0';
        $this->saved = null;
    }

    public function cancelReset(): void
    {
        $this->resetting = false;
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

        app(UtilityReadingResetService::class)->reset($this->unit, $this->utility->id, (float) $this->resetTo, now()->toDateString());

        $this->resetting = false;
        $this->resetTo = '';
        $this->saved = __('Meter reset. The next reading starts from here.');
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
        ])->title(__($this->utility->name));
    }
}
