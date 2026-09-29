<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum PlanInterval: int implements HasLabel
{
    case Monthly = 1;
    case Quarterly = 2;
    case Yearly = 3;
    // A plan that never expires (e.g. the free sign-up plan). Modeled as a period
    // of LIFETIME_YEARS rather than a NULL ends_at: subscriptions.ends_at is NOT NULL
    // and every expiry/renewal/grace path compares against it, so a far-future date
    // keeps all of them correct with no special case.
    case Lifetime = 4;

    public const LIFETIME_YEARS = 100;

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::Yearly => __('Yearly'),
            self::Lifetime => __('Forever'),
        };
    }

    /** Add one interval to a date. */
    public function addInterval(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $date->addMonth(),
            self::Quarterly => $date->addMonths(3),
            self::Yearly => $date->addYear(),
            self::Lifetime => $date->addYears(self::LIFETIME_YEARS),
        };
    }

    /** Number of months in this interval (for proration). */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
            self::Lifetime => 12 * self::LIFETIME_YEARS,
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
