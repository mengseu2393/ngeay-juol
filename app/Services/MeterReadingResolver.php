<?php

namespace App\Services;

use App\Enums\MeterStatus;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "what does this cycle subtract from?".
 *
 * Every billing screen used to inline the same query — latest utility_usages row
 * for (unit, property_utility), else 0 — which is why a swapped meter looked
 * like a huge negative and needed a fake baseline row. Routing them all through
 * here means the meter rules live in a single file that can be reasoned about,
 * tested, and switched off wholesale via config('utilities.meters').
 *
 * Resolution order for a room + utility:
 *   1. meters ON and the room has an ACTIVE meter → that meter's last reading,
 *      or its installed_reading when it has never been read;
 *   2. otherwise → legacy path: latest usage row's new_reading, else 0.
 *
 * (2) is what keeps every property that has not been backfilled working exactly
 * as before.
 */
class MeterReadingResolver
{
    public function enabled(): bool
    {
        return (bool) config('utilities.meters', true);
    }

    /** The device currently on the wall for this room + utility, if any. */
    public function activeMeter(int $unitId, int $propertyUtilityId): ?UtilityMeter
    {
        if (! $this->enabled()) {
            return null;
        }

        return UtilityMeter::query()
            ->active()
            ->forRoom($unitId, $propertyUtilityId)
            ->orderByDesc('installed_on')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Previous index plus the context billing needs to explain it.
     *
     * @return array{previous: float, meter: ?UtilityMeter, usage: ?UtilityUsage, source: 'meter_reading'|'meter_install'|'usage'|'none'}
     */
    public function previous(int $unitId, int $propertyUtilityId): array
    {
        $meter = $this->activeMeter($unitId, $propertyUtilityId);

        if ($meter) {
            $usage = $meter->latestUsage();

            return [
                'previous' => (float) ($usage?->new_reading ?? $meter->installed_reading),
                'meter' => $meter,
                'usage' => $usage,
                'source' => $usage ? 'meter_reading' : 'meter_install',
            ];
        }

        $usage = $this->legacyLatestUsage($unitId, $propertyUtilityId);

        return [
            'previous' => (float) ($usage?->new_reading ?? 0),
            'meter' => null,
            'usage' => $usage,
            'source' => $usage ? 'usage' : 'none',
        ];
    }

    /**
     * The pre-meter lookup, kept verbatim (date, then id) so a room without a
     * meter row bills byte-for-byte the way it did before this feature landed.
     */
    public function legacyLatestUsage(int $unitId, int $propertyUtilityId): ?UtilityUsage
    {
        return UtilityUsage::query()
            ->where('unit_id', $unitId)
            ->where('property_utility_id', $propertyUtilityId)
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Shorthand for the many call sites that only want the number. */
    public function previousReading(int $unitId, int $propertyUtilityId): float
    {
        return $this->previous($unitId, $propertyUtilityId)['previous'];
    }

    /**
     * Baseline for a reading taken ON a given date — used by the room pages,
     * where a reading can be back-dated or re-entered for the same day, so the
     * baseline must be the latest reading STRICTLY BEFORE that date.
     *
     * With a meter, the first reading of a device measures from its
     * installed_reading, so real consumption is billed from day one. Without a
     * meter the old rule stands: a room's first ever reading is a zero-usage
     * baseline, because there is nothing to say where the counter started.
     *
     * @return array{old: float, amount: float, meter: ?UtilityMeter}
     */
    public function baselineFor(int $unitId, int $propertyUtilityId, string $date, float $new): array
    {
        $meter = $this->activeMeter($unitId, $propertyUtilityId);

        if ($meter && $meter->installed_on?->toDateString() <= $date) {
            $prior = $meter->usages()
                ->whereDate('reading_date', '<', $date)
                ->orderByDesc('reading_date')
                ->orderByDesc('id')
                ->first();

            $old = (float) ($prior?->new_reading ?? $meter->installed_reading);

            return ['old' => $old, 'amount' => $meter->consumption($old, $new), 'meter' => $meter];
        }

        $prior = UtilityUsage::query()
            ->where('unit_id', $unitId)
            ->where('property_utility_id', $propertyUtilityId)
            ->whereDate('reading_date', '<', $date)
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->first();

        if ($prior && $prior->new_reading !== null) {
            $old = (float) $prior->new_reading;

            return ['old' => $old, 'amount' => round(max(0, $new - $old), 3), 'meter' => null];
        }

        return ['old' => $new, 'amount' => 0.0, 'meter' => null];
    }

    /**
     * Consumption for a cycle. Delegates to the meter (multiplier + rollover)
     * when there is one, else the original `max(0, new − old)`.
     */
    public function consumption(float $previous, float $current, ?UtilityMeter $meter = null): float
    {
        return $meter
            ? $meter->consumption($previous, $current)
            : round(max(0, $current - $previous), 3);
    }

    /**
     * Retire the current meter and install its replacement in one transaction.
     * `$finalReading` defaults to the old meter's current index, i.e. "nothing
     * more was consumed on it", which is the honest default when the landlord
     * has already invoiced the cycle.
     */
    public function replace(UtilityMeter $meter, string $date, float $installedReading, ?float $finalReading = null, array $attributes = []): UtilityMeter
    {
        return DB::transaction(function () use ($meter, $date, $installedReading, $finalReading, $attributes): UtilityMeter {
            $meter->forceFill([
                'status' => MeterStatus::Removed->value,
                'removed_on' => $date,
                'final_reading' => $finalReading ?? $meter->currentIndex(),
            ])->save();

            return UtilityMeter::create(array_merge([
                'property_utility_id' => $meter->property_utility_id,
                'landlord_id' => $meter->landlord_id,
                'scope' => $meter->scope->value,
                'unit_id' => $meter->unit_id,
                'floor_number' => $meter->floor_number,
                'parent_meter_id' => $meter->parent_meter_id,
                'digits' => $meter->digits,
                'multiplier' => $meter->multiplier,
                'installed_on' => $date,
                'installed_reading' => $installedReading,
                'status' => MeterStatus::Active->value,
                'replaced_meter_id' => $meter->getKey(),
                'created_by_id' => auth()->id(),
            ], $attributes));
        });
    }
}
