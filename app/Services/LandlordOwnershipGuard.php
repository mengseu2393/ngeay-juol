<?php

namespace App\Services;

use App\Models\Scopes\LandlordScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The assertion counterpart of {@see LandlordScope}.
 *
 * A handful of write paths must read a parent row with `withoutGlobalScopes()`
 * in order to derive `landlord_id`/`property_id` from it (see CLAUDE.md —
 * "crossing the landlord boundary"). That is correct when the id comes from an
 * internal relation, and a cross-tenant hole when the id came from the client:
 * Livewire round-trips public array properties (`rooms.0.rental_id`) and
 * Filament `Hidden` fields (`rental_id`, `readings.*.utility_usage_id`) back to
 * the browser, where they can be rewritten and re-submitted with a perfectly
 * valid snapshot checksum. Nothing in the form schema constrains them — the
 * only Select with a scoped option list (`unit_id`) is not what the writer
 * reads, and Filament only emits an `exists` rule for `->relationship()`
 * selects, not `->options()` ones.
 *
 * So: keep the scope bypass (the read is legitimate), and assert ownership on
 * the loaded record before writing anything derived from it.
 *
 * Semantics deliberately mirror LandlordScope, with one exception:
 *  - unauthenticated (CLI, queue workers, seeders) → allowed, exactly as the
 *    scope applies no constraint there;
 *  - super_admin / support → allowed, cross-landlord by design;
 *  - landlord / landlord_manager → the record's landlord must match their
 *    `effectiveLandlordId()`;
 *  - any other authenticated actor (e.g. a tenant) → DENIED. The scope leaves
 *    those unscoped and relies on per-record policies instead; a guard on a
 *    write path has no such fallback, so it fails closed.
 */
final class LandlordOwnershipGuard
{
    /**
     * Does $record belong to the landlord the current actor may write to?
     *
     * A record whose landlord cannot be established (null `landlord_id`, no
     * `resolveLandlordId()`) is treated as NOT owned — fail closed.
     */
    public static function owns(?Model $record): bool
    {
        if ($record === null) {
            return false;
        }

        $user = Auth::user();

        if (! $user) {
            return true; // CLI / queue / seeder: no tenant context to violate.
        }

        if (method_exists($user, 'isPlatformStaff') && $user->isPlatformStaff()) {
            return true;
        }

        $actorLandlordId = method_exists($user, 'effectiveLandlordId')
            ? $user->effectiveLandlordId()
            : null;

        if ($actorLandlordId === null) {
            return false;
        }

        $recordLandlordId = self::landlordIdOf($record);

        return $recordLandlordId !== null && (int) $recordLandlordId === (int) $actorLandlordId;
    }

    /**
     * abort(403) unless $record belongs to the acting landlord.
     *
     * Also aborts when $record is null, so a `find()` that missed (because the
     * id was forged, or the row is gone) can be funnelled through here instead
     * of a separate null check.
     */
    public static function assertOwned(?Model $record): void
    {
        abort_unless(self::owns($record), 403, __('You do not have access to that record.'));
    }

    /**
     * abort(403) unless every one of $ids resolves to a record of $modelClass
     * owned by the acting landlord. One query, used as a pre-flight check
     * before a batch write loop so the 403 is not swallowed by the loop's
     * per-row `catch (\Throwable)`.
     *
     * @param  class-string<Model>  $modelClass
     * @param  iterable<mixed>  $ids
     */
    public static function assertOwnsAll(string $modelClass, iterable $ids): void
    {
        $ids = collect($ids)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $records = $modelClass::withoutGlobalScopes()->whereKey($ids->all())->get();

        abort_unless($records->count() === $ids->count(), 403, __('You do not have access to that record.'));

        foreach ($records as $record) {
            self::assertOwned($record);
        }
    }

    /** The record's landlord, from its column or its `resolveLandlordId()` fallback. */
    private static function landlordIdOf(Model $record): ?int
    {
        $landlordId = $record->getAttribute('landlord_id');

        if ($landlordId === null && method_exists($record, 'resolveLandlordId')) {
            $landlordId = $record->resolveLandlordId();
        }

        return $landlordId === null ? null : (int) $landlordId;
    }
}
