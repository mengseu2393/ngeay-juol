<?php

namespace App\Enums;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum PlanInterval: int implements HasLabel
{
    case Monthly = 1;
    case Quarterly = 2;
    case Yearly = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::Yearly => __('Yearly'),
        };
    }

    /** Add one interval to a date. */
    public function addInterval(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $date->addMonth(),
            self::Quarterly => $date->addMonths(3),
            self::Yearly => $date->addYear(),
        };
    }

    /** Number of months in this interval (for proration). */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
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
