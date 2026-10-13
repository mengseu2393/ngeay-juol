<?php

namespace App\Services;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Rental;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

/** Ends a room's active agreement and (optionally) frees the room. */
class EndTenancyService
{
    /**
     * @throws ValidationException
     */
    public function end(Unit $unit, string $endDate, RentalStatus $outcome, bool $freeRoom = true): Rental
    {
        if (! in_array($outcome, [RentalStatus::Vacated, RentalStatus::Expired], true)) {
            throw ValidationException::withMessages(['status' => __('Choose how the agreement ended.')]);
        }

        $rental = Rental::query()
            ->where('unit_id', $unit->getKey())
            ->where('status', RentalStatus::Active->value)
            ->first();

        if (! $rental) {
            throw ValidationException::withMessages(['unitId' => __('This room has no active tenancy.')]);
        }

        if ($rental->start_date && $endDate < $rental->start_date->toDateString()) {
            throw ValidationException::withMessages(['endDate' => __('The end date cannot be before the start date.')]);
        }

        $rental->update(['status' => $outcome, 'end_date' => $endDate]);

        if ($freeRoom) {
            $unit->update(['status' => UnitStatus::Available]);
        }

        return $rental;
    }
}
