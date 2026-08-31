<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\ActiveProperty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard counterpart to {@see \App\Filament\Concerns\ScopesToActiveProperty}
 * (which does the same job for resources).
 *
 * Every widget on the dashboard must follow the sidebar's property switcher, or
 * the page shows portfolio-wide stats next to property-scoped charts and the two
 * silently disagree. Widgets whose model has no `property_id` scope through a
 * relationship with {@see scopeThroughRelation()} instead.
 */
trait HasActivePropertyScope
{
    protected function activePropertyId(): ?int
    {
        return ActiveProperty::id();
    }

    /** Narrow a query whose model carries `property_id` directly. No active property → unchanged. */
    protected function scopeToActiveProperty(Builder $query, string $column = 'property_id'): Builder
    {
        $propertyId = $this->activePropertyId();

        if ($propertyId !== null) {
            $query->where($query->getModel()->getTable().'.'.$column, $propertyId);
        }

        return $query;
    }

    /** Narrow through a relation that carries `property_id` (e.g. Payment → invoice). */
    protected function scopeThroughRelation(Builder $query, string $relation, string $column = 'property_id'): Builder
    {
        $propertyId = $this->activePropertyId();

        if ($propertyId !== null) {
            $query->whereHas($relation, fn (Builder $q) => $q->where($column, $propertyId));
        }

        return $query;
    }

    /** Heading suffix so a number is never ambiguous about what it counts. */
    protected function scopeLabel(): string
    {
        return ActiveProperty::name() ?? __('All properties');
    }
}
