<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SubscriptionAction: int implements HasLabel
{
    case Started = 1;
    case Renewed = 2;
    case Upgraded = 3;
    case Downgraded = 4;
    case PlanChanged = 5;
    case Cancelled = 6;
    case Reactivated = 7;
    case Suspended = 8;
    case Extended = 9;
    case Shortened = 10;
    case TrialStarted = 11;

    public function getLabel(): string
    {
        return match ($this) {
            self::Started => __('Started'),
            self::Renewed => __('Renewed'),
            self::Upgraded => __('Upgraded'),
            self::Downgraded => __('Downgraded'),
            self::PlanChanged => __('Plan changed'),
            self::Cancelled => __('Cancelled'),
            self::Reactivated => __('Reactivated'),
            self::Suspended => __('Suspended'),
            self::Extended => __('Extended'),
            self::Shortened => __('Shortened'),
            self::TrialStarted => __('Trial started'),
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
