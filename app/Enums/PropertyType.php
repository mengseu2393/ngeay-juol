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
            self::House => __('Whole house'),
            self::Condo => __('Condominium'),
            self::Villa => __('Villa'),
            self::Commercial => __('Commercial'),
            self::RoomRental => __('Room rental'),
            self::Other => __('Other'),
        };
    }

    /**
     * The two kinds a landlord picks from when adding or editing a property: a whole
     * house let as one, or a house split into rooms. The owner dropped the rest
     * (Condo, Villa, …) from the pickers; they stay as cases because properties
     * saved earlier still carry them, and filters/labels must keep reading them.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return [self::RoomRental, self::House];
    }

    /** What a new property starts as. */
    public static function default(): self
    {
        return self::RoomRental;
    }

    /**
     * Picker options: the offered kinds, plus $current when it's a retired kind, so
     * editing an old Condo property neither fails validation nor silently changes it.
     *
     * @return array<int, string>
     */
    public static function pickerOptions(self|int|string|null $current = null): array
    {
        $current = $current instanceof self ? $current : self::tryFrom((int) $current);

        return collect(self::offered())
            ->when($current && ! in_array($current, self::offered(), true), fn ($c) => $c->push($current))
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }

    /** Every kind, for filters and lists over saved properties. @return array<int, string> */
    public static function options(): array
    {
        // "Other" stays last even though Room rental was added after it.
        return collect(self::cases())
            ->sortBy(fn (self $case) => $case === self::Other ? PHP_INT_MAX : $case->value)
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
