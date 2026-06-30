<?php

namespace App\Console\Commands;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Rental;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class UpdateRentalStatuses extends Command
{
    protected $signature = 'rentals:update-statuses';

    protected $description = 'Expire active rentals past their end date and free their units.';

    public function handle(): int
    {
        $today = Carbon::today();
        $expired = 0;

        Rental::withoutGlobalScopes()
            ->where('status', RentalStatus::Active->value)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today->toDateString())
            ->with('unit')                              // eager-load (no N+1, unlike the old job)
            ->chunkById(100, function ($rentals) use (&$expired) {
                foreach ($rentals as $rental) {
                    $rental->status = RentalStatus::Expired;
                    $rental->save();

                    // Free the unit if it isn't taken by another active rental.
                    if ($rental->unit && $rental->unit->status === UnitStatus::Occupied) {
                        $rental->unit->status = UnitStatus::Available;
                        $rental->unit->save();
                    }

                    $expired++;
                }
            });

        $this->info("Expired {$expired} rental(s).");

        return self::SUCCESS;
    }
}
