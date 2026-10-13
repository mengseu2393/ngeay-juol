<?php

namespace App\Support;

use App\Enums\BillingType;

/**
 * The utilities a property can charge for (Electricity, Water, ...), their
 * translated labels and sensible defaults. Shared by the Filament panel
 * (PropertyUtilityResource delegates here), Simple Mode and the /m mobile shell.
 */
class UtilityCatalog
{
    /** @return array<string, string> stored name => translated label */
    public static function options(): array
    {
        return [
            'Electricity' => __('Electricity'),
            'Water' => __('Water'),
            'Gas' => __('Gas'),
            'Internet' => __('Internet'),
            'Trash' => __('Trash'),
            'Cleaning' => __('Cleaning'),
            'Parking' => __('Parking'),
            'Security' => __('Security'),
            'Other' => __('Other'),
        ];
    }

    /** Translated label for a stored name; a custom name is shown as typed. */
    public static function label(string $name): string
    {
        return static::options()[$name] ?? $name;
    }

    /** @return array{unit: string, billing: BillingType} */
    public static function defaults(string $name): array
    {
        return match ($name) {
            'Electricity' => ['unit' => 'kWh', 'billing' => BillingType::Metered],
            'Water' => ['unit' => 'm³', 'billing' => BillingType::Metered],
            'Gas' => ['unit' => 'm³', 'billing' => BillingType::Metered],
            'Internet' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Trash' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Cleaning' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Parking' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Security' => ['unit' => 'month', 'billing' => BillingType::Flat],
            default => ['unit' => 'unit', 'billing' => BillingType::Flat],
        };
    }
}
