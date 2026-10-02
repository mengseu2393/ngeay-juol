<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ErrorLogType: int implements HasColor, HasLabel
{
    /** Unhandled exception — a 500 page, a failed job or command. */
    case Exception = 1;

    /** A form rejected by validation — plain HTTP forms and Livewire/Filament panel forms. */
    case Validation = 2;

    /** Request refused before the app ran: 419 page expired (CSRF/session), 429 rate limit. */
    case Blocked = 3;

    /** Caught in code and shown to the user only as a red notification (e.g. move-in refused). */
    case ActionFailed = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::Exception => __('Server error'),
            self::Validation => __('Form rejected'),
            self::Blocked => __('Request blocked'),
            self::ActionFailed => __('Action failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Exception => 'danger',
            self::Validation => 'warning',
            self::Blocked => 'info',
            self::ActionFailed => 'warning',
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
