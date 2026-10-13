<?php

namespace App\Services;

use App\Enums\ReadingType;
use App\Models\Unit;
use App\Models\UtilityUsage;
use Illuminate\Validation\ValidationException;

/** Records one ongoing meter reading for a room (consumption measured from the previous reading). */
class MeterReadingRecorder
{
    /**
     * @throws ValidationException
     */
    public function record(Unit $unit, int $propertyUtilityId, float $newReading, string $date, ReadingType $type = ReadingType::Actual): UtilityUsage
    {
        if ($newReading < 0 || $newReading > 999999999) {
            throw ValidationException::withMessages(['newReading' => __('Enter a valid meter reading.')]);
        }

        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => __('The reading date cannot be in the future.')]);
        }

        $baseline = app(MeterReadingResolver::class)->baselineFor($unit->getKey(), $propertyUtilityId, $date, $newReading);

        $previous = app(MeterReadingResolver::class)->previous($unit->getKey(), $propertyUtilityId);
        if ($previous['source'] !== 'none' && $newReading < $baseline['old'] && ! $baseline['meter']?->digits) {
            throw ValidationException::withMessages(['newReading' => __('The reading cannot be lower than the previous one (:value).', [
                'value' => rtrim(rtrim(number_format($baseline['old'], 3), '0'), '.'),
            ])]);
        }

        // One reading per room + utility + day: re-saving the same day corrects it.
        $usage = UtilityUsage::query()
            ->where('unit_id', $unit->getKey())
            ->where('property_utility_id', $propertyUtilityId)
            ->whereDate('reading_date', $date)
            ->first() ?? new UtilityUsage([
                'unit_id' => $unit->getKey(),
                'property_utility_id' => $propertyUtilityId,
                'reading_date' => $date,
            ]);

        if ($usage->exists && $usage->invoiceLine()->exists()) {
            throw ValidationException::withMessages(['date' => __('That reading is already billed on an invoice. Use Reading history to correct it.')]);
        }

        $usage->fill([
            'rental_id' => $unit->activeRental?->getKey(),
            'reading_type' => $type,
            'old_reading' => $baseline['old'],
            'new_reading' => $newReading,
            'amount_used' => $baseline['amount'],
            'recorded_by_id' => auth()->id(),
        ])->save();

        return $usage;
    }
}
