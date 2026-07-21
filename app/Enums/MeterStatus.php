<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MeterStatus: string implements HasColor, HasLabel
{
    /** Currently on the wall and being read. At most one per room + utility. */
    case Active = 'active';

    /** Taken off the wall — kept forever for history, never read again. */
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Removed => __('Removed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Removed => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
