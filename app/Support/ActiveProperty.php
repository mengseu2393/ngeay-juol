<?php

namespace App\Support;

use App\Models\Property;
use App\Models\Scopes\LandlordScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * The landlord's currently-selected property "context".
 *
 * Stored in the HTTP session (no DB column, no migration). Every read
 * re-validates the stored id against the {@see LandlordScope}
 * visible set, so a stale, deleted, or tampered id fails closed to null — and
 * scoped queries simply revert to landlord-wide.
 *
 * Auto-default: a landlord/manager who hasn't chosen yet this session lands in
 * their first property (by name — the top of the switcher) rather than the
 * landlord-wide view, so Simple Mode never opens on "Choose a property first".
 * Picking "All properties" is remembered (the ALL sentinel) and respected —
 * except for a single-property landlord, who is always in their one property.
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

    /** Session value meaning "the landlord explicitly chose All properties". */
    private const ALL = 0;

    private static ?int $resolved = null;

    private static bool $didResolve = false;

    /** The resolved property row, kept so id() validating it and model() reading it share one query. */
    private static ?Property $resolvedModel = null;

    /** Validated id of the active property, or null if none / no longer visible. */
    public static function id(): ?int
    {
        if (self::$didResolve) {
            return self::$resolved;
        }

        self::$didResolve = true;

        $sessionId = Session::get(self::SESSION_KEY);
        $choseAll = $sessionId !== null && (int) $sessionId === self::ALL;

        if ($sessionId !== null && ! $choseAll) {
            // Runs through LandlordScope: true only if the current user may see it.
            $property = Property::query()->find($sessionId);

            if ($property) {
                self::$resolvedModel = $property;

                return self::$resolved = (int) $sessionId;
            }

            self::forgetSession(); // stale / deleted / tampered → fall through
        }

        // Auto-default (landlords/managers only — platform staff are unscoped
        // and would land in an arbitrary landlord's property).
        $user = Auth::user();
        if ($user && method_exists($user, 'effectiveLandlordId') && $user->effectiveLandlordId() !== null) {
            $ids = Property::query()->orderBy('name')->limit(2)->pluck('id');
            if ($ids->count() === 1 || ($ids->isNotEmpty() && ! $choseAll)) {
                return self::$resolved = (int) $ids->first();
            }
        }

        return self::$resolved = null;
    }

    /** The active Property model, or null. */
    public static function model(): ?Property
    {
        $id = self::id();

        if ($id === null) {
            return null;
        }

        // Per-request memo: the switcher, topbar and every Simple Mode component ask.
        if (self::$resolvedModel?->getKey() !== $id) {
            self::$resolvedModel = Property::find($id);
        }

        return self::$resolvedModel;
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
        self::$resolvedModel = null;
    }

    /** Drop back to the landlord-wide ("All properties") view, and remember that choice. */
    public static function clear(): void
    {
        Session::put(self::SESSION_KEY, self::ALL);
        self::$didResolve = false;
        self::$resolved = null;
        self::$resolvedModel = null;
    }

    private static function forgetSession(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
