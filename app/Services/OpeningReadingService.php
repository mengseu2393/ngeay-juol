<?php

namespace App\Services;

use App\Enums\ReadingType;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "What did the meter say on the day we started?" — recorded once per room, per
 * utility, before the first invoice.
 *
 * Without it the first cycle bills the whole lifetime index of the meter: a room
 * whose electricity counter already reads 4,120 gets charged for 4,120 kWh it
 * never used, because {@see MeterReadingResolver} has nothing to subtract from
 * and falls back to 0.
 *
 * Where the number is stored depends on whether the room has a meter, and the
 * two are not interchangeable:
 *
 *  - **With a meter** it belongs in `installed_reading` — that column *is* the
 *    opening index of the device, and {@see MeterReadingResolver::baselineFor()}
 *    measures the meter's first real reading from it, so genuine consumption is
 *    billed from day one. Writing a zero-usage row instead would work, but it
 *    puts a reading in the history that nobody ever took.
 *  - **Without a meter** there is nowhere to put it but a baseline
 *    {@see UtilityUsage} row (old = new, amount_used = 0), which is the legacy
 *    shape the resolver still reads for un-metered rooms.
 *
 * Both paths are one-shot. Once a meter has been read, or a room has any usage
 * row, its opening index is settled: rewriting it would retroactively change
 * what the first cycle charged, so those rooms come back locked.
 */
final class OpeningReadingService
{
    /** Room has a meter that has never been read — sets `installed_reading`. */
    public const OPEN_METER = 'open_meter';

    /** Room has no meter and no readings — writes a baseline usage row. */
    public const OPEN_LEGACY = 'open_legacy';

    /** The meter has been read; its opening index is already in the books. */
    public const LOCKED_METER = 'locked_meter';

    /** Un-metered room that already has readings. */
    public const LOCKED_USAGE = 'locked_usage';

    public function __construct(private readonly MeterReadingResolver $resolver) {}

    /**
     * One row per room: whether it still needs an opening index, and what it
     * already has if it does not.
     *
     * Built from three queries rather than per-room lookups — a 40-room property
     * would otherwise cost 80 round trips just to draw the form.
     *
     * @return Collection<int, array{unit: Unit, state: string, baseline: ?float, meter: ?UtilityMeter}>
     */
    public function rows(PropertyUtility $utility): Collection
    {
        $units = Unit::query()
            ->where('property_id', $utility->property_id)
            ->orderBy('room_number')
            ->get();

        $meters = $this->activeMetersByUnit($utility, $units->modelKeys());

        // Which meters have been read, and which un-metered rooms have any row.
        $usageRows = UtilityUsage::query()
            ->where('property_utility_id', $utility->getKey())
            ->whereIn('unit_id', $units->modelKeys() ?: [0])
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->get(['id', 'unit_id', 'meter_id', 'new_reading', 'reading_date']);

        $latestByUnit = $usageRows->groupBy('unit_id')->map->first();
        $readMeterIds = $usageRows->pluck('meter_id')->filter()->unique()->all();

        return $units->mapWithKeys(function (Unit $unit) use ($meters, $latestByUnit, $readMeterIds): array {
            $meter = $meters->get($unit->getKey());
            $latest = $latestByUnit->get($unit->getKey());

            [$state, $baseline] = match (true) {
                $meter && in_array($meter->getKey(), $readMeterIds, true)
                    // A reading exists for this device; show it, falling back to the
                    // opening index if the row somehow belongs to another room.
                    => [self::LOCKED_METER, (float) ($latest->new_reading ?? $meter->installed_reading)],
                // `!== null`, not a bare `$meter`: match(true) compares strictly,
                // so a truthy model object never equals true.
                $meter !== null => [self::OPEN_METER, null],
                $latest !== null => [self::LOCKED_USAGE, (float) $latest->new_reading],
                default => [self::OPEN_LEGACY, null],
            };

            return [$unit->getKey() => [
                'unit' => $unit,
                'state' => $state,
                'baseline' => $baseline,
                'meter' => $meter,
            ]];
        });
    }

    /**
     * Record the opening index for every room that still needs one.
     *
     * Rooms that are locked, blank, or not in this property are skipped rather
     * than rejected: the form submits every room it drew, and a half-filled form
     * is the normal way to do this a few rooms at a time.
     *
     * @param  array<int|string, mixed>  $values  unit id => opening index
     * @return array{meters: int, baselines: int, skipped: int}
     */
    public function apply(PropertyUtility $utility, string $date, array $values): array
    {
        $rows = $this->rows($utility);
        $result = ['meters' => 0, 'baselines' => 0, 'skipped' => 0];

        return DB::transaction(function () use ($rows, $values, $date, $utility, $result): array {
            foreach ($values as $unitId => $value) {
                $row = $rows->get((int) $unitId);

                if (! $row || $value === null || $value === '') {
                    continue;
                }

                if (! in_array($row['state'], [self::OPEN_METER, self::OPEN_LEGACY], true)) {
                    $result['skipped']++;

                    continue;
                }

                if ($row['state'] === self::OPEN_METER) {
                    $this->openMeter($row['meter'], (float) $value, $date);
                    $result['meters']++;

                    continue;
                }

                $this->openLegacy($utility, $row['unit'], (float) $value, $date);
                $result['baselines']++;
            }

            return $result;
        });
    }

    /**
     * The device's opening index. `installed_on` moves with it only when it is
     * unset or later than the reading date — a meter installed in March that is
     * being opened with a January index was clearly on the wall in January, but
     * an earlier install date is a fact we have no reason to overwrite.
     */
    private function openMeter(UtilityMeter $meter, float $value, string $date): void
    {
        $meter->forceFill(array_filter([
            'installed_reading' => $value,
            'installed_on' => (! $meter->installed_on || $meter->installed_on->toDateString() > $date)
                ? $date
                : null,
        ], fn ($v) => $v !== null))->save();
    }

    /**
     * A zero-consumption row for an un-metered room: old = new, so the next
     * cycle subtracts from it and this one charges nothing.
     * `meter_id` is left to {@see UtilityUsage::booted()}, which stamps the
     * active meter if one appears later.
     */
    private function openLegacy(PropertyUtility $utility, Unit $unit, float $value, string $date): void
    {
        UtilityUsage::create([
            'unit_id' => $unit->getKey(),
            'property_utility_id' => $utility->getKey(),
            'rental_id' => $unit->activeRental?->getKey(),
            'reading_type' => ReadingType::Actual->value,
            'reading_date' => $date,
            'old_reading' => $value,
            'new_reading' => $value,
            'amount_used' => 0,
            // NOT NULL. Falls back to the landlord when nobody is signed in, so a
            // console backfill attributes the row to whose books it lands in
            // rather than failing the insert.
            'recorded_by_id' => auth()->id() ?? $utility->landlord_id,
        ]);
    }

    /**
     * Active meters keyed by room. Returns nothing when the meter layer is off
     * so every room takes the legacy path, matching {@see MeterReadingResolver}.
     *
     * @param  array<int, int>  $unitIds
     * @return Collection<int, UtilityMeter>
     */
    private function activeMetersByUnit(PropertyUtility $utility, array $unitIds): Collection
    {
        if (! $this->resolver->enabled() || $unitIds === []) {
            return collect();
        }

        return UtilityMeter::query()
            ->active()
            ->where('property_utility_id', $utility->getKey())
            ->whereIn('unit_id', $unitIds)
            ->orderBy('installed_on')
            ->orderBy('id')
            ->get()
            // Latest install wins, mirroring MeterReadingResolver::activeMeter().
            ->keyBy('unit_id');
    }
}
