<?php

namespace App\Http\Middleware;

use App\Support\DeviceSession;
use Closure;
use Filament\Facades\Filament;
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

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (Filament::getCurrentPanel()?->getId() !== 'admin' && DeviceSession::wantsLongSession($request)) {
            return $next($request);
        }

        $lastActivity = $request->session()->get('_last_activity_at');

        if ($lastActivity !== null && now()->timestamp - $lastActivity > self::MINUTES * 60) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // The panel has no login page of its own — auth lives on the shared /login route.
            return redirect()->route('login')
                ->with('error', __('You were signed out after :minutes minutes of inactivity.', ['minutes' => self::MINUTES]));
        }

        $request->session()->put('_last_activity_at', now()->timestamp);

        return $next($request);
    }
}
