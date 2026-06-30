<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BillingType: int implements HasLabel
{
    case Metered = 1;   // usage × rate (e.g. electricity per kWh, water per m³)
    case Flat = 2;      // fixed amount per room (e.g. trash, flat water)
    case Shared = 3;    // a master-meter total split across rooms

    public function getLabel(): string
    {
        return match ($this) {
            self::Metered => __('Metered (usage × rate)'),
            self::Flat => __('Flat (fixed per room)'),
            self::Shared => __('Shared (split a master meter)'),
        };
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
