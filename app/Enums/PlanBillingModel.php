<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PlanBillingModel: int implements HasLabel
{
    case Flat = 1;
    case PerUnit = 2;
    case Tiered = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::Flat => __('Flat (fixed price)'),
            self::PerUnit => __('Per unit (metered)'),
            self::Tiered => __('Tiered (bracket-based)'),
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
