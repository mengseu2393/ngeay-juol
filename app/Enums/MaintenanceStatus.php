<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MaintenanceStatus: int implements HasColor, HasLabel
{
    case Open = 1;
    case InProgress = 2;
    case Resolved = 3;
    case Closed = 4;
    case Cancelled = 5;

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::InProgress => __('In Progress'),
            self::Resolved => __('Resolved'),
            self::Closed => __('Closed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'info',
            self::Resolved => 'success',
            self::Closed => 'gray',
            self::Cancelled => 'danger',
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
