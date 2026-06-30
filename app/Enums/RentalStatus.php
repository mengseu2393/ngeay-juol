<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RentalStatus: int implements HasColor, HasLabel
{
    case Active = 1;
    case Expired = 2;
    case Vacated = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Expired => __('Expired'),
            self::Vacated => __('Vacated'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Expired => 'warning',
            self::Vacated => 'gray',
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
