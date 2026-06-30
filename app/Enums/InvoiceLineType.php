<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoiceLineType: int implements HasLabel
{
    case Rent = 1;
    case Utility = 2;
    case AdHoc = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::Rent => __('Rent'),
            self::Utility => __('Utility'),
            self::AdHoc => __('Ad-hoc Charge'),
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
