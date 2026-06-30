<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChatRoomType: int implements HasLabel
{
    case Direct = 1;
    case Group = 2;
    case Support = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Group => 'Group',
            self::Support => 'Support',
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
