<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChatMessageType: int implements HasLabel
{
    case Text = 1;
    case Image = 2;
    case File = 3;
    case System = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Image => 'Image',
            self::File => 'File',
            self::System => 'System',
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
