<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UnitStatus: int implements HasColor, HasLabel
{
    case Available = 1;
    case Occupied = 2;
    case Maintenance = 3;
    case Unavailable = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => __('Available'),
            self::Occupied => __('Occupied'),
            self::Maintenance => __('Under Maintenance'),
            self::Unavailable => __('Unavailable'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Occupied => 'info',
            self::Maintenance => 'warning',
            self::Unavailable => 'gray',
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
