<?php

namespace App\Filament\Concerns;

use App\Support\ActiveProperty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Makes a Filament resource follow the sidebar's {@see ActiveProperty} context:
 *  - getEloquentQuery() narrows the list to the active property (composes on top
 *    of LandlordScope as `landlord_id = X AND property_id = Y` — pure narrowing,
 *    never a conflict). No active property → unchanged (landlord-wide).
 *  - the resource is grouped under the "PropertyContext" nav group (labelled with
 *    the active property's name) while in context, and under its own fallback
 *    group otherwise.
 *  - it only appears in the sidebar once a property is active (platform staff
 *    keep their cross-property list at all times).
 *
 * Resources whose model has no direct `property_id` column override
 * {@see applyActivePropertyScope()} to scope through a relationship instead.
 */
trait ScopesToActiveProperty
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $propertyId = ActiveProperty::id();
        if ($propertyId !== null) {
            static::applyActivePropertyScope($query, $propertyId);
        }

        return $query;
    }

    /** Default: the model carries `property_id` directly. */
    protected static function applyActivePropertyScope(Builder $query, int $propertyId): void
    {
        $query->where($query->getModel()->getTable() . '.property_id', $propertyId);
    }

    public static function getNavigationGroup(): ?string
    {
        return ActiveProperty::id() !== null
            ? ActiveProperty::NAV_GROUP
            : static::propertyContextFallbackGroup();
    }

    /** Group shown when NO property is active (staff browsing cross-property). */
    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Properties';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ActiveProperty::id() !== null
            || (bool) auth()->user()?->isPlatformStaff();
    }
}
