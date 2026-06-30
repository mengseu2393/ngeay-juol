<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubscriptionPaymentStatus: int implements HasColor, HasLabel
{
    case Pending = 1;
    case Succeeded = 2;
    case Failed = 3;
    case Refunded = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Succeeded => __('Succeeded'),
            self::Failed => __('Failed'),
            self::Refunded => __('Refunded'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Refunded => 'gray',
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
