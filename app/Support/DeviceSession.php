<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Session policy by device. A landlord's phone (and the installed app on any
 * device) is a daily tool that should stay signed in like a native app: a
 * remember-me of ~30 days and no 15-minute idle logout. Desktop keeps the idle
 * logout (see IdleTimeout).
 *
 * "Installed app" (display-mode: standalone) is only knowable in the browser,
 * so components.rw-display-mode-cookie writes it into a plain cookie that the
 * server reads here. It is excluded from cookie encryption (bootstrap/app.php)
 * because JavaScript writes it. Spoofing it only lengthens the spoofer's own
 * session, which a remember-me tick already does.
 */
class DeviceSession
{
    public const DISPLAY_MODE_COOKIE = 'rw_display_mode';

    /** 30 days, in minutes (SessionGuard::setRememberDuration()). */
    public const REMEMBER_MINUTES = 60 * 24 * 30;

    public static function isInstalledApp(Request $request): bool
    {
        return $request->cookie(self::DISPLAY_MODE_COOKIE) === 'standalone';
    }

    public static function wantsLongSession(Request $request): bool
    {
        return SimpleLandlordMode::isMobileUserAgent($request) || self::isInstalledApp($request);
    }
}
