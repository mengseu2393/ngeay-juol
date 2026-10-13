<?php

namespace App\Services;

use App\Enums\ReadingType;
use App\Models\Unit;
use App\Models\UtilityUsage;
use Illuminate\Validation\ValidationException;

/**
 * "Reset" a room's utility reading: record a NEW STARTING POINT (e.g. 0 after
 * the meter was replaced or rolled over). History is never deleted; the reset
 * row is flagged `is_reset` with amount_used 0, so the next reading is
 * measured from it and the usage straddling the swap is not billed.
 */
class UtilityReadingResetService
{
    /**
     * @throws ValidationException
     */
    public function reset(Unit $unit, int $propertyUtilityId, float $startingReading, string $date): UtilityUsage
    {
        if ($startingReading < 0 || $startingReading > 999999999) {
            throw ValidationException::withMessages(['startingReading' => __('Enter a valid meter reading.')]);
        }

        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => __('The reading date cannot be in the future.')]);
        }

        $latest = UtilityUsage::query()
            ->where('unit_id', $unit->getKey())
            ->where('property_utility_id', $propertyUtilityId)
            ->orderByDesc('reading_date')->orderByDesc('id')
            ->first();

        // Only as the newest entry: a back-dated reset would rewrite what later readings were measured from.
        if ($latest && $latest->reading_date->toDateString() > $date) {
            throw ValidationException::withMessages(['date' => __('The reset date must be on or after the latest reading (:date).', [
                'date' => $latest->reading_date->format('d M Y'),
            ])]);
        }

        // One reading per room + utility + day (re-saving the same day updates it).
        $usage = UtilityUsage::query()
            ->where('unit_id', $unit->getKey())
            ->where('property_utility_id', $propertyUtilityId)
            ->whereDate('reading_date', $date)
            ->first() ?? new UtilityUsage([
                'unit_id' => $unit->getKey(),
                'property_utility_id' => $propertyUtilityId,
                'reading_date' => $date,
            ]);

        if ($usage->exists && ! $usage->is_reset) {
            throw ValidationException::withMessages(['date' => __('There is already a reading on that date.')]);
        }

        $usage->fill([
            'rental_id' => $unit->activeRental?->getKey(),
            'reading_type' => ReadingType::Actual,
            'old_reading' => $startingReading,
            'new_reading' => $startingReading,
            'amount_used' => 0,
            'is_reset' => true,
            'recorded_by_id' => auth()->id(),
        ])->save();

        return $usage;
    }
}
