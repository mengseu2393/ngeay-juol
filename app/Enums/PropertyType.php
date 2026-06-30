<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PropertyType: int implements HasLabel
{
    case Apartment = 1;
    case House = 2;
    case Condo = 3;
    case Villa = 4;
    case Commercial = 5;
    case Other = 6;

    public function getLabel(): string
    {
        return match ($this) {
            self::Apartment => __('Apartment'),
            self::House => __('House'),
            self::Condo => __('Condominium'),
            self::Villa => __('Villa'),
            self::Commercial => __('Commercial'),
            self::Other => __('Other'),
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
