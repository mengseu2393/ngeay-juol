<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Controls how the first (possibly partial) month's rent is calculated
 * when a tenant moves in mid-cycle.
 */
enum FirstMonthBillingMode: string implements HasColor, HasDescription, HasLabel
{
    /** Charge the full monthly rent regardless of the move-in date. */
    case FullMonth = 'full_month';

    /** Charge daily rate × days remaining in the month. */
    case Prorated = 'prorated';

    /**
     * Charge half the monthly rent if the tenant moves in after the
     * configured cutoff day; otherwise charge full rent.
     */
    case HalfMonth = 'half_month';

    public function getLabel(): string
    {
        return match ($this) {
            self::FullMonth  => __('Full month'),
            self::Prorated   => __('Prorated (daily rate × days)'),
            self::HalfMonth  => __('Half month (after cutoff day)'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::FullMonth  => __('Charge the full monthly rent even if the tenant moves in mid-month.'),
            self::Prorated   => __('Charge only for the days actually occupied: (rent ÷ days in month) × days remaining.'),
            self::HalfMonth  => __('If move-in is after the cutoff day, charge half rent; otherwise charge the full amount.'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::FullMonth  => 'info',
            self::Prorated   => 'success',
            self::HalfMonth  => 'warning',
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
