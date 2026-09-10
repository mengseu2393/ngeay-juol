<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a tenancy's security deposit stands at move-out.
 *
 * Draft    — the settlement has been calculated but not yet handed to the tenant.
 * Settled  — the landlord confirmed the deductions and the refund figure.
 * Refunded — the refund has actually been paid out (reference/date recorded).
 */
enum DepositSettlementStatus: int implements HasColor, HasLabel
{
    case Draft = 1;
    case Settled = 2;
    case Refunded = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Settled => __('Settled'),
            self::Refunded => __('Refunded'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Settled => 'info',
            self::Refunded => 'success',
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
