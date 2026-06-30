<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SubscriptionAccess: int implements HasLabel
{
    case Full = 1;
    case PastDue = 2;
    case ReadOnly = 3;
    case Revoked = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => __('Full access'),
            self::PastDue => __('Past due (pay to renew)'),
            self::ReadOnly => __('Read only'),
            self::Revoked => __('Access revoked'),
        };
    }

    public function isAccessible(): bool
    {
        return $this !== self::Revoked;
    }

    public function isWritable(): bool
    {
        return $this === self::Full || $this === self::PastDue;
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
