<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReadingType: int implements HasLabel
{
    case Actual = 1;
    case Estimated = 2;
    case Fixed = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::Actual => __('Actual (metered)'),
            self::Estimated => __('Estimated'),
            self::Fixed => __('Fixed charge'),
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
