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
    case RoomRental = 7;

    public function getLabel(): string
    {
        return match ($this) {
            self::Apartment => __('Apartment'),
            self::House => __('House'),
            self::Condo => __('Condominium'),
            self::Villa => __('Villa'),
            self::Commercial => __('Commercial'),
            self::RoomRental => __('Room rental'),
            self::Other => __('Other'),
        };
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        // "Other" stays last even though Room rental was added after it.
        return collect(self::cases())
            ->sortBy(fn (self $case) => $case === self::Other ? PHP_INT_MAX : $case->value)
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
