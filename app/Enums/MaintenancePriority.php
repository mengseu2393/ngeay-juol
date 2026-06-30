<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MaintenancePriority: int implements HasColor, HasLabel
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Urgent = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => __('Low'),
            self::Medium => __('Medium'),
            self::High => __('High'),
            self::Urgent => __('Urgent'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
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
