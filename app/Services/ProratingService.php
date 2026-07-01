<?php

namespace App\Services;

use App\Enums\FirstMonthBillingMode;
use App\Models\PropertySetting;
use Carbon\Carbon;

/**
 * Calculates the rent amount due for a (possibly partial) first billing period
 * based on the property's configured FirstMonthBillingMode.
 *
 * All three modes share the same contract:
 *   compute(setting, monthlyRent, periodStart, periodEnd) → float
 *
 * The caller is responsible for distinguishing a "first invoice" from subsequent
 * ones — this service simply applies the rule without making that determination.
 */
class ProratingService
{
    /**
     * Compute the rent charge for the given billing period.
     *
     * @param  PropertySetting|null  $setting      Property-level settings (null → full month).
     * @param  float                 $monthlyRent  The tenant's agreed monthly rent.
     * @param  Carbon                $periodStart  First day of the billing period.
     * @param  Carbon                $periodEnd    Last day of the billing period (inclusive).
     * @return float                               Rounded to 2 decimal places.
     */
    public static function compute(
        ?PropertySetting $setting,
        float $monthlyRent,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): float {
        $mode = $setting?->first_month_billing_mode ?? FirstMonthBillingMode::FullMonth;

        $daysInMonth = $periodEnd->daysInMonth ?: 30;

        // Number of days actually covered by this period (inclusive both ends).
        $daysOccupied = $periodStart->diffInDays($periodEnd) + 1;

        // If the period spans a full month (or more) we always charge the full amount
        // regardless of the mode — proration only applies to partial first months.
        if ($daysOccupied >= $daysInMonth) {
            return round($monthlyRent, 2);
        }

        return match ($mode) {
            FirstMonthBillingMode::FullMonth => round($monthlyRent, 2),

            FirstMonthBillingMode::Prorated => round(
                ($monthlyRent / $daysInMonth) * $daysOccupied,
                2,
            ),

            FirstMonthBillingMode::HalfMonth => self::computeHalfMonth(
                $setting,
                $monthlyRent,
                $periodStart,
            ),
        };
    }

    /**
     * Half-month mode: if the move-in day is AFTER the cutoff day the tenant
     * is charged half a month's rent; otherwise the full month is charged.
     *
     *   move-in day > cutoff_day  →  monthlyRent / 2
     *   move-in day ≤ cutoff_day  →  monthlyRent
     */
    private static function computeHalfMonth(
        ?PropertySetting $setting,
        float $monthlyRent,
        Carbon $periodStart,
    ): float {
        $cutoffDay = $setting?->proration_cutoff_day ?? 15;
        $moveInDay = $periodStart->day;

        return $moveInDay > $cutoffDay
            ? round($monthlyRent / 2, 2)
            : round($monthlyRent, 2);
    }

    /**
     * Calculate the upfront deposit amount for a new tenancy.
     *
     * @param  PropertySetting|null  $setting
     * @param  float                 $monthlyRent
     * @return float                 Deposit amount (may be 0).
     */
    public static function depositAmount(?PropertySetting $setting, float $monthlyRent): float
    {
        $months = $setting?->upfront_deposit_months ?? 0;

        return round($monthlyRent * max(0, (int) $months), 2);
    }
}
