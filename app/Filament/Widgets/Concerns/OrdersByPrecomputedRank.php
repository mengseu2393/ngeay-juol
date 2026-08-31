<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps a ranking that was computed in PHP intact through the database
 * round-trip, by emitting it as a CASE expression over the primary key.
 *
 * Some dashboard rankings — outstanding balance across mixed-currency legacy
 * invoices, consumption against a room's own history — can't be expressed as a
 * portable ORDER BY. Those widgets rank in PHP, then reload the records; without
 * this the rows come back in whatever order the engine likes.
 *
 * Filament only supplies a default sort when the query carries none of its own
 * (see CanSortRecords::applyDefaultSortingToTableQuery), so an explicit order
 * here survives.
 */
trait OrdersByPrecomputedRank
{
    /** @param  array<int, int|string>  $ids  Primary keys in the order they should appear. */
    protected function orderByRank(Builder $query, array $ids): Builder
    {
        $ids = array_values(array_map('intval', $ids));

        if ($ids === []) {
            return $query;
        }

        $cases = [];
        foreach ($ids as $position => $id) {
            $cases[] = "WHEN {$id} THEN {$position}";
        }

        $key = $query->getModel()->getQualifiedKeyName();

        return $query->orderByRaw('CASE '.$key.' '.implode(' ', $cases).' ELSE '.count($ids).' END');
    }
}
