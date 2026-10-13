<?php

namespace App\Services;

use App\Enums\BillingType;
use App\Models\PropertyUtility;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Which of a property's utilities are read from a meter, and how a reading is
 * shown. Pure helpers shared by the Filament panel (UnitResource delegates
 * here), Simple Mode and the /m mobile shell — kept out of Filament so the
 * mobile shell and the services don't depend on a panel resource class.
 */
class MeterUtilities
{
    /** The property's meter-based, active utilities (Metered / Shared) for a room. */
    public static function forUnit(Unit $unit): Collection
    {
        return PropertyUtility::query()
            ->where('property_id', $unit->property_id)
            ->whereIn('billing_type', [BillingType::Metered->value, BillingType::Shared->value])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Helper text under a reading input: what the consumption will be measured
     * from. Three cases — a previous reading, a meter that has just been
     * installed (its opening index), or nothing at all (legacy baseline rule).
     */
    public static function readingHint(int $unitId, int $utilityId, ?string $uom = null): string
    {
        $context = app(MeterReadingResolver::class)->previous($unitId, $utilityId);
        $suffix = $uom ? ' '.$uom : '';
        $value = static::trimReading($context['previous']).$suffix;

        return match ($context['source']) {
            'meter_reading' => __('Previous: :value', ['value' => $value])
                .($context['usage']?->reading_date ? ' · '.$context['usage']->reading_date->format('d M Y') : ''),
            'meter_install' => __('Meter installed at :value — first reading bills from there.', ['value' => $value]),
            'usage' => __('Previous: :value', ['value' => $value]),
            default => __('First reading — sets the starting baseline (no consumption billed).'),
        };
    }

    /** Trim a decimal(…,3) reading to a clean string ("1,251", "11", "1.5"). */
    public static function trimReading($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3), '0'), '.');
    }
}
