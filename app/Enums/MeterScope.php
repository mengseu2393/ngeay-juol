<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a meter measures. Only {@see self::Unit} is wired into billing today;
 * Floor and Property exist so shared / main meters (and the allocation rules
 * that go with them) can be added later without another migration.
 */
enum MeterScope: string implements HasLabel
{
    /** One room's own meter — the only scope billing reads right now. */
    case Unit = 'unit';

    /** Shared by every room on a floor; consumption needs an allocation rule. */
    case Floor = 'floor';

    /** The landlord's main / incoming meter, parent of the sub-meters. */
    case Property = 'property';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unit => __('Room meter'),
            self::Floor => __('Floor / shared meter'),
            self::Property => __('Main (property) meter'),
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
