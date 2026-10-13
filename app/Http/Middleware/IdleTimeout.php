<?php

namespace App\Http\Middleware;

use App\Support\DeviceSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out an authenticated staff/landlord user after 15 minutes of
 * inactivity, regardless of SESSION_LIFETIME (which only governs how long
 * the session cookie/store entry survives, not idle activity within it).
 *
 * Desktop only in the landlord panel: a phone or the installed app is a daily
 * tool that stays signed in like a native app (DeviceSession). The platform
 * staff panel (/admin) keeps the idle logout on every device.
 */
class IdleTimeout
{
    public const MINUTES = 15;

    public const SESSION_KEY = '_last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // By path, not Filament::getCurrentPanel(): outside a panel (the /m mobile
        // shell) the "current" panel is the default one — admin.
        if (! $request->is('admin', 'admin/*') && DeviceSession::wantsLongSession($request)) {
            return $next($request);
        }

        $lastActivity = $request->session()->get(self::SESSION_KEY);

        if ($lastActivity !== null && now()->timestamp - $lastActivity > self::MINUTES * 60) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // The panel has no login page of its own — auth lives on the shared /login route.
            return redirect()->route('login')
                ->with('error', __('You were signed out after :minutes minutes of inactivity.', ['minutes' => self::MINUTES]));
        }

        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        return $next($request);
    }
}
