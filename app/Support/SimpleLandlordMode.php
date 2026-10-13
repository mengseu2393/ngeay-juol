<?php

namespace App\Support;

use App\Models\User;
use App\Providers\Filament\LandlordPanelProvider;
use Illuminate\Http\Request;

class SimpleLandlordMode
{
    public static function canUse(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(['landlord', 'landlord_manager', 'super_admin']);
    }

    public static function enabledFor(?User $user): bool
    {
        return self::canUse($user) && (bool) $user?->prefers_simple_landlord_mode;
    }

    /** Session key + lifetime for {@see markEscape()}. */
    private const ESCAPE_SESSION_KEY = 'simple_mode_escape_until';

    private const ESCAPE_MINUTES = 20;

    /**
     * Panel-relative paths a locked (phone) device may open besides Simple Mode
     * itself: the full pages Simple Mode links out to, plus setup/subscription
     * pages other middleware redirects to (bouncing those would loop).
     */
    private const LOCKED_ALLOWED_PATHS = [
        'simple', 'simple/*', 'setup', 'add-property', 'unit-overview/*', 'billing',
        'property-settings',
        'properties', 'properties/*',
        'property-utilities', 'property-utilities/*',
        'rentals', 'rentals/*',
        'units/*', // the room's own edit page, linked from the unit page
    ];

    /**
     * Phones are hard-locked to Simple Mode: no preference toggle, no ?from=simple
     * or escape window, no sidebar. The installed PWA gets the same lock
     * client-side (see LandlordPanelProvider's HEAD_END script).
     */
    public static function isLocked(Request $request): bool
    {
        return self::canUse($request->user()) && self::isMobileUserAgent($request);
    }

    /**
     * Whether this page is part of a Simple Mode errand (phone lock, ?from=simple,
     * or the escape window after it) and so must render without the sidebar and
     * with a way back — on any device.
     */
    public static function isSimpleErrand(Request $request): bool
    {
        if (self::isLocked($request)) {
            return true;
        }

        return self::canUse($request->user())
            && ($request->query('from') === 'simple' || self::hasActiveEscape($request));
    }

    private static function isLockedAllowedPath(Request $request): bool
    {
        return $request->is(self::lockedAllowedPatterns());
    }

    /** @return list<string> request()->is() patterns, e.g. "app/units/*" */
    private static function lockedAllowedPatterns(): array
    {
        return array_map(fn (string $p) => LandlordPanelProvider::PATH.'/'.$p, self::LOCKED_ALLOWED_PATHS);
    }

    /**
     * The same whitelist as a regex source for the installed-PWA lock, which can
     * only run client-side (display-mode: standalone). Built from
     * LOCKED_ALLOWED_PATHS with Str::is() semantics ("*" = anything) so the two
     * can't drift apart again — a hand-copied list once missed units/* and
     * unit-overview/*, bouncing "Edit room" to the Invoices tab in the app only.
     *
     * Matches a path without its leading/trailing slash, e.g. "app/units/5/edit".
     * Plain enough to mean the same thing in PCRE and JavaScript.
     */
    public static function lockedAllowedPathsRegex(): string
    {
        $alternatives = array_map(
            fn (string $pattern) => str_replace('\*', '.*', preg_quote($pattern, '/')),
            self::lockedAllowedPatterns(),
        );

        return '^(?:'.implode('|', $alternatives).')$';
    }

    public static function shouldRedirectToSimple(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return false;
        }

        if (self::isLocked($request)) {
            $panel = LandlordPanelProvider::PATH;

            return ($request->is($panel) || $request->is($panel.'/*'))
                && ! self::isLockedAllowedPath($request);
        }

        if (! self::enabledFor($request->user())) {
            return false;
        }

        // Simple Mode's own screens link out to full pages on purpose (Property
        // Settings, Utility Rates, Monthly Billing, ...) — ?from=simple marks
        // that as a deliberate one-task exit, not a stray link to bounce back.
        //
        // But a single link only carries that marker for its OWN request — the
        // very next click inside that full page (e.g. "Edit" on a property
        // reached via the Settings hub) has no ?from=simple at all, and would
        // otherwise get bounced straight back here mid-task. hasActiveEscape()
        // extends a short grace window server-side so a whole full-mode errand
        // (not just its first click) stays reachable, without needing every
        // link in every resource to carry the marker forever.
        if ($request->query('from') === 'simple') {
            self::markEscape($request);

            return false;
        }

        if (self::hasActiveEscape($request)) {
            self::markEscape($request); // sliding window: still-active use keeps extending it

            return false;
        }

        $panel = LandlordPanelProvider::PATH;

        return ($request->is($panel) || $request->is($panel.'/*'))
            && ! $request->is($panel.'/simple', $panel.'/simple/*')
            // The setup wizard is already phone-first; bouncing it to Simple Mode
            // would loop with RedirectToLandlordSetup.
            && ! $request->is($panel.'/setup', $panel.'/add-property', $panel.'/unit-overview/*');
    }

    /** Start/extend the full-mode escape window from this request. */
    public static function markEscape(Request $request): void
    {
        $request->session()->put(self::ESCAPE_SESSION_KEY, now()->addMinutes(self::ESCAPE_MINUTES)->timestamp);
    }

    /** Whether a ?from=simple escape (or a page reached within its window) is still active. */
    public static function hasActiveEscape(Request $request): bool
    {
        $until = $request->session()->get(self::ESCAPE_SESSION_KEY);

        return is_int($until) && now()->timestamp < $until;
    }

    /** Clear the escape window — called when a landlord deliberately returns to Simple Mode. */
    public static function clearEscape(Request $request): void
    {
        $request->session()->forget(self::ESCAPE_SESSION_KEY);
    }

    /**
     * Phone-sized UA sniff — the panel itself has no viewport signal server-side,
     * so this is a best-effort proxy, not a hard device check. Tablets (iPad's
     * UA reads as desktop Safari) are deliberately treated as "not mobile".
     */
    public static function isMobileUserAgent(Request $request): bool
    {
        return (bool) preg_match('/Mobi|Android|iPhone|iPod|Windows Phone|BlackBerry/i', (string) $request->userAgent());
    }

    /**
     * One-time nudge into Simple Mode for a landlord on a phone who has never
     * opted into it — not a hard lock. Gated on a session flag (not the
     * persisted `prefers_simple_landlord_mode` preference) so a manual "Switch
     * to full mode" during the same session sticks instead of being immediately
     * bounced back here; a fresh session on mobile will suggest it again.
     */
    public static function shouldAutoSwitchToSimple(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return false;
        }

        $user = $request->user();

        // Phones are covered by the hard lock in shouldRedirectToSimple().
        if (! self::canUse($user) || self::enabledFor($user) || self::isLocked($request)) {
            return false;
        }

        if ($request->query('from') === 'simple' || self::hasActiveEscape($request)) {
            return false;
        }

        if (! self::isMobileUserAgent($request)) {
            return false;
        }

        if ($request->session()->get('mobile_simple_mode_suggested')) {
            return false;
        }

        $panel = LandlordPanelProvider::PATH;

        return ($request->is($panel) || $request->is($panel.'/*'))
            && ! $request->is($panel.'/simple', $panel.'/simple/*')
            // The setup wizard is already phone-first; bouncing it to Simple Mode
            // would loop with RedirectToLandlordSetup.
            && ! $request->is($panel.'/setup', $panel.'/add-property', $panel.'/unit-overview/*');
    }
}
