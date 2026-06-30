<?php

namespace App\Console\Commands;

use App\Enums\UnitStatus;
use App\Models\Unit;
use App\Services\TenancyService;
use Illuminate\Console\Command;

/**
 * Reconcile legacy data: any room that is still "Available" while it holds an
 * active tenancy is flipped to "Occupied". Occupy-only on purpose — it never
 * frees a room, so deliberately "held" rooms (ended tenancy, room kept
 * unavailable) are left untouched.
 */
class SyncUnitOccupancy extends Command
{
    protected $signature = 'units:sync-occupancy';

    protected $description = 'Mark rooms Occupied when they have an active tenancy (reconciles legacy data).';

    public function handle(): int
    {
        $occupied = 0;

        Unit::withoutGlobalScopes()
            ->where('status', UnitStatus::Available->value)
            ->chunkById(200, function ($units) use (&$occupied) {
                foreach ($units as $unit) {
                    if (TenancyService::hasActiveTenancy($unit->id)) {
                        $unit->status = UnitStatus::Occupied;
                        $unit->save();
                        $occupied++;
                    }
                }
            });

        $this->info("Marked {$occupied} room(s) Occupied.");

        return self::SUCCESS;
    }
}
