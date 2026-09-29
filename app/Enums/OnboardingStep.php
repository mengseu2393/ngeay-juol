<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Setup-wizard steps (LandlordSetup page). Step 1 — the account itself — is the
 * public /register form, so a stored step always starts at Properties.
 */
enum OnboardingStep: int implements HasLabel
{
    case Properties = 2;
    case Utilities = 3;
    case Rooms = 4;
    case Review = 5;

    public const TOTAL = 5;

    public function getLabel(): string
    {
        return match ($this) {
            self::Properties => __('Properties'),
            self::Utilities => __('Utilities'),
            self::Rooms => __('Rooms & prices'),
            self::Review => __('Review'),
        };
    }

    public function next(): self
    {
        return self::tryFrom($this->value + 1) ?? self::Review;
    }

    public function previous(): self
    {
        return self::tryFrom($this->value - 1) ?? self::Properties;
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
