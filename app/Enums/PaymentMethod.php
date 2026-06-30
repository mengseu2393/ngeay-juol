<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: int implements HasLabel
{
    case Cash = 1;
    case BankTransfer = 2;
    case Card = 3;
    case MobilePayment = 4;
    case Cheque = 5;
    case Other = 6;

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::BankTransfer => __('Bank Transfer'),
            self::Card => __('Card'),
            self::MobilePayment => __('Mobile Payment'),
            self::Cheque => __('Cheque'),
            self::Other => __('Other'),
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
