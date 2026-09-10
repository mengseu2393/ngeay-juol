<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Why money was withheld from a security deposit at move-out.
 *
 * A structured category (plus a free-text reason on the row) rather than one
 * boolean/column per kind of damage — see CLAUDE.md, "New policy toggles ...
 * are modeled as structured/enum-driven rule records".
 */
enum DepositDeductionCategory: int implements HasColor, HasLabel
{
    case UnpaidRent = 1;
    case UnpaidUtilities = 2;
    case Damage = 3;
    case Cleaning = 4;
    case LostKeys = 5;
    case Other = 6;

    public function getLabel(): string
    {
        return match ($this) {
            self::UnpaidRent => __('Unpaid rent'),
            self::UnpaidUtilities => __('Unpaid utilities'),
            self::Damage => __('Damage'),
            self::Cleaning => __('Cleaning'),
            self::LostKeys => __('Lost keys'),
            self::Other => __('Other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::UnpaidRent, self::UnpaidUtilities => 'warning',
            self::Damage, self::LostKeys => 'danger',
            self::Cleaning => 'info',
            self::Other => 'gray',
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
