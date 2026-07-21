<?php

namespace App\Console\Commands;

use App\Enums\MeterStatus;
use App\Models\PropertyUtility;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates the meters that were always implied by the reading history, so
 * existing properties get the new behaviour without anyone re-keying indexes.
 *
 * For each (room, utility) the readings are walked in order and a new meter is
 * opened wherever the index DROPS — that is exactly a meter swap, whether it was
 * recorded with the old "reset" action or by someone typing the new device's
 * number. The first meter's installed_reading is the oldest row's old_reading,
 * which is the opening index the landlord entered on day one.
 *
 * Idempotent: rooms that already have a meter are skipped, so it is safe to
 * re-run after importing more history. Reversible: delete the meter rows (or
 * flip UTILITY_METERS=false) and everything falls back to the legacy path.
 */
class BackfillUtilityMeters extends Command
{
    protected $signature = 'utilities:backfill-meters
                            {--property= : Only this property id}
                            {--dry-run : Report what would be created, write nothing}';

    protected $description = 'Create utility meters from existing reading history';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $utilities = PropertyUtility::withoutGlobalScopes()
            ->when($this->option('property'), fn ($q, $id) => $q->where('property_id', $id))
            ->get();

        if ($utilities->isEmpty()) {
            $this->warn('No utilities found.');

            return self::SUCCESS;
        }

        $metersCreated = 0;
        $usagesStamped = 0;
        $roomsSkipped = 0;

        foreach ($utilities as $utility) {
            $readingsByUnit = UtilityUsage::withoutGlobalScopes()
                ->where('property_utility_id', $utility->getKey())
                ->whereNotNull('unit_id')
                ->orderBy('reading_date')
                ->orderBy('id')
                ->get()
                ->groupBy('unit_id');

            foreach ($readingsByUnit as $unitId => $readings) {
                $unitId = (int) $unitId;

                $alreadyHasMeter = UtilityMeter::withoutGlobalScopes()
                    ->where('property_utility_id', $utility->getKey())
                    ->where('unit_id', $unitId)
                    ->exists();

                if ($alreadyHasMeter) {
                    $roomsSkipped++;

                    continue;
                }

                $segments = $this->splitAtMeterChanges($readings);

                if ($dryRun) {
                    $metersCreated += count($segments);
                    $usagesStamped += $readings->count();

                    continue;
                }

                DB::transaction(function () use ($segments, $utility, $unitId, &$metersCreated, &$usagesStamped): void {
                    $previousMeter = null;

                    foreach ($segments as $index => $segment) {
                        /** @var Collection<int, UtilityUsage> $segment */
                        $first = $segment->first();
                        $last = $segment->last();
                        $isCurrent = $index === count($segments) - 1;

                        $meter = UtilityMeter::withoutGlobalScopes()->create([
                            'property_utility_id' => $utility->getKey(),
                            'landlord_id' => $utility->landlord_id,
                            'unit_id' => $unitId,
                            'installed_on' => $first->reading_date,
                            'installed_reading' => $first->old_reading ?? 0,
                            'status' => $isCurrent ? MeterStatus::Active->value : MeterStatus::Removed->value,
                            'removed_on' => $isCurrent ? null : $last->reading_date,
                            'final_reading' => $isCurrent ? null : $last->new_reading,
                            'replaced_meter_id' => $previousMeter?->getKey(),
                            'notes' => __('Created from existing reading history.'),
                        ]);

                        UtilityUsage::withoutGlobalScopes()
                            ->whereIn('id', $segment->pluck('id'))
                            ->update(['meter_id' => $meter->getKey()]);

                        $previousMeter = $meter;
                        $metersCreated++;
                        $usagesStamped += $segment->count();
                    }
                });
            }
        }

        $this->info(sprintf(
            '%s%d meter(s) from %d reading(s); %d room(s) already had a meter.',
            $dryRun ? '[dry run] would create ' : 'Created ',
            $metersCreated,
            $usagesStamped,
            $roomsSkipped,
        ));

        return self::SUCCESS;
    }

    /**
     * Split one room's reading history wherever the index went backwards — each
     * drop is a device change, so each segment becomes its own meter.
     *
     * @param  Collection<int, UtilityUsage>  $readings
     * @return array<int, Collection<int, UtilityUsage>>
     */
    protected function splitAtMeterChanges(Collection $readings): array
    {
        $segments = [];
        $current = collect();
        $previousIndex = null;

        foreach ($readings as $reading) {
            $opening = (float) ($reading->old_reading ?? 0);

            // A cycle normally opens where the last one closed; opening lower
            // than that can only mean the counter restarted on a new device.
            if ($previousIndex !== null && $opening < $previousIndex - 0.0005 && $current->isNotEmpty()) {
                $segments[] = $current;
                $current = collect();
            }

            $current->push($reading);
            $previousIndex = (float) ($reading->new_reading ?? $opening);
        }

        if ($current->isNotEmpty()) {
            $segments[] = $current;
        }

        return $segments;
    }
}
