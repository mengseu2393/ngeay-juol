<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatus: int implements HasColor, HasLabel
{
    case Pending = 1;
    case Trial = 2;
    case Active = 3;
    case Cancelled = 4;
    case Suspended = 5;

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Trial => __('Trial'),
            self::Active => __('Active'),
            self::Cancelled => __('Cancelled'),
            self::Suspended => __('Suspended'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Trial => 'info',
            self::Active => 'success',
            self::Cancelled => 'warning',
            self::Suspended => 'danger',
        };
    }

    /** Statuses that still allow platform access (before effective check). */
    public function isAccessible(): bool
    {
        return in_array($this, [self::Pending, self::Trial, self::Active], true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
