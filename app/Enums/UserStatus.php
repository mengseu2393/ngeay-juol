<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserStatus: int implements HasColor, HasLabel
{
    case Inactive = 0;
    case Active = 1;
    case Suspended = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Inactive => __('Inactive'),
            self::Suspended => __('Suspended'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
            self::Suspended => 'danger',
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
