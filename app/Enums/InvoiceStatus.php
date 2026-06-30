<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: int implements HasColor, HasLabel
{
    case Draft = 1;
    case Pending = 2;
    case Partial = 3;
    case Paid = 4;
    case Overdue = 5;
    case Cancelled = 6;

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Pending => __('Pending'),
            self::Partial => __('Partially Paid'),
            self::Paid => __('Paid'),
            self::Overdue => __('Overdue'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Partial => 'info',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** Statuses that count as settled (no further payment expected). */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
