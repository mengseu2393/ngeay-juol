<?php

namespace App\Services;

use App\Enums\RentalStatus;
use App\Models\Rental;
use Carbon\CarbonInterface;

class TenancyService
{
    /**
     * Application-layer guard for "one active tenancy per unit" — backs up the
     * DB-level generated-column unique index (which may be skipped on engines
     * that reject the DDL).
     */
    public static function hasOverlap(
        int $unitId,
        CarbonInterface|string $startDate,
        CarbonInterface|string|null $endDate = null,
        ?int $excludeRentalId = null,
    ): bool {
        $end = $endDate ?: '9999-12-31';

        return Rental::withoutGlobalScopes()
            ->where('unit_id', $unitId)
            ->where('status', RentalStatus::Active->value)
            ->when($excludeRentalId, fn ($q) => $q->where('id', '!=', $excludeRentalId))
            // overlap: existing.start <= new.end AND (existing.end IS NULL OR existing.end >= new.start)
            ->where('start_date', '<=', $end)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $startDate);
            })
            ->exists();
    }

    /**
     * Does this unit already have an active tenancy? This mirrors the actual DB
     * constraint (uniq_active_tenancy_per_unit, on the generated active_unit_id
     * column), which permits only one Active rental per unit regardless of dates —
     * so it catches conflicts a date-overlap check can miss (e.g. nonsense dates).
     */
    public static function hasActiveTenancy(int $unitId, ?int $excludeRentalId = null): bool
    {
        return Rental::withoutGlobalScopes()
            ->where('unit_id', $unitId)
            ->where('status', RentalStatus::Active->value)
            ->when($excludeRentalId, fn ($q) => $q->where('id', '!=', $excludeRentalId))
            ->exists();
    }
}
