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

    public static function shouldRedirectToSimple(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return false;
        }

        if (! self::enabledFor($request->user())) {
            return false;
        }

        // Simple Mode's own screens link out to full pages on purpose (Property
        // Settings, Utility Rates, Monthly Billing, ...) — ?from=simple marks
        // that as a deliberate one-task exit, not a stray link to bounce back.
        if ($request->query('from') === 'simple') {
            return false;
        }

        $panel = LandlordPanelProvider::PATH;

        return ($request->is($panel) || $request->is($panel.'/*'))
            && ! $request->is($panel.'/simple', $panel.'/simple/*');
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

        if (! self::canUse($user) || self::enabledFor($user)) {
            return false;
        }

        if ($request->query('from') === 'simple') {
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
            && ! $request->is($panel.'/simple', $panel.'/simple/*');
    }
}
