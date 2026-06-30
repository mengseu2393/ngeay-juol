<?php

namespace App\Support;

use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * The landlord's currently-selected property "context".
 *
 * Stored in the HTTP session (no DB column, no migration). Every read
 * re-validates the stored id against the {@see \App\Models\Scopes\LandlordScope}
 * visible set, so a stale, deleted, or tampered id fails closed to null — and
 * scoped queries simply revert to landlord-wide. A landlord/manager who owns
 * exactly one property is auto-defaulted into it.
 *
 * Interface is deliberately shaped like Filament::getTenant() (id()/model()) so
 * this can graduate to real Filament tenancy later by swapping the implementation
 * behind these calls, not every call site.
 */
class ActiveProperty
{
    public const SESSION_KEY = 'active_property_id';

    /** Sidebar nav-group key for the active-property group (registered in the panel). */
    public const NAV_GROUP = 'PropertyContext';

    private static ?int $resolved = null;

    private static bool $didResolve = false;

    /** Validated id of the active property, or null if none / no longer visible. */
    public static function id(): ?int
    {
        if (self::$didResolve) {
            return self::$resolved;
        }

        self::$didResolve = true;

        $sessionId = Session::get(self::SESSION_KEY);

        if ($sessionId !== null) {
            // Runs through LandlordScope: true only if the current user may see it.
            if (Property::query()->whereKey($sessionId)->exists()) {
                return self::$resolved = (int) $sessionId;
            }

            self::forgetSession(); // stale / deleted / tampered → fall through
        }

        // Auto-default: a landlord/manager with exactly one property is always in it.
        $user = Auth::user();
        if ($user && method_exists($user, 'effectiveLandlordId') && $user->effectiveLandlordId() !== null) {
            $ids = Property::query()->limit(2)->pluck('id');
            if ($ids->count() === 1) {
                return self::$resolved = (int) $ids->first();
            }
        }

        return self::$resolved = null;
    }

    /** The active Property model, or null. */
    public static function model(): ?Property
    {
        $id = self::id();

        return $id === null ? null : Property::find($id);
    }

    /** Convenience: the active property's display name, or null. */
    public static function name(): ?string
    {
        return self::model()?->name;
    }

    /** Select a property as the active context (validates on next read). */
    public static function set(int|string|null $id): void
    {
        if ($id === null || $id === '') {
            self::clear();

            return;
        }

        Session::put(self::SESSION_KEY, (int) $id);
        self::$didResolve = false;
        self::$resolved = null;
    }

    /** Drop back to the landlord-wide ("All properties") view. */
    public static function clear(): void
    {
        self::forgetSession();
        self::$didResolve = false;
        self::$resolved = null;
    }

    private static function forgetSession(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
