<?php

namespace App\Services;

use App\Enums\ReadingType;
use App\Models\Unit;
use App\Models\UtilityUsage;
use Illuminate\Support\Facades\DB;

/**
 * "Meter readings" for one room: one reading per meter-based utility, all on
 * the same date — the form behind UnitResource's row action, the Edit room
 * page's header action, and the /m Edit room screen's readings sheet.
 *
 * Consumption is derived automatically from the latest reading strictly before
 * the date ({@see MeterReadingResolver::baselineFor()}): with a meter it
 * measures from the meter's opening index, without one the very first reading
 * is a zero-usage baseline. Re-saving the same day corrects that day's row
 * instead of stacking a duplicate.
 */
class RoomMeterReadingsService
{
    /**
     * @param  array<int|string, mixed>  $meters  property_utility_id => new reading; blanks are skipped
     * @return int how many readings were saved
     */
    public function save(Unit $unit, string $date, int|ReadingType $type, array $meters): int
    {
        $type = $type instanceof ReadingType ? $type->value : $type;

        // Only the utilities this room's property actually reads from a meter —
        // a tampered payload can't slip in another property's (or a flat) utility.
        $allowed = MeterUtilities::forUnit($unit)->keyBy('id');

        // Linked to the current tenancy (when occupied) so rental-scoped waivers resolve.
        $rentalId = $unit->activeRental?->getKey();

        // All-or-nothing across the utilities.
        return DB::transaction(function () use ($meters, $allowed, $unit, $date, $type, $rentalId): int {
            $count = 0;

            foreach ($meters as $utilityId => $value) {
                $utilityId = (int) $utilityId;

                if ($value === null || $value === '' || ! $allowed->has($utilityId)) {
                    continue;
                }

                $new = (float) $value;
                $baseline = app(MeterReadingResolver::class)->baselineFor($unit->getKey(), $utilityId, $date, $new);

                UtilityUsage::updateOrCreate(
                    [
                        'unit_id' => $unit->getKey(),
                        'property_utility_id' => $utilityId,
                        'reading_date' => $date,
                    ],
                    [
                        'rental_id' => $rentalId,
                        'reading_type' => $type,
                        'old_reading' => $baseline['old'],
                        'new_reading' => $new,
                        'amount_used' => $baseline['amount'],
                        'recorded_by_id' => auth()->id(),
                        // landlord_id auto-fills via BelongsToLandlord / resolveLandlordId().
                    ],
                );
                $count++;
            }

            return $count;
        });
    }
}
