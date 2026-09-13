<?php

namespace App\Http\Middleware;

use App\Providers\Filament\LandlordPanelProvider;
use App\Support\SimpleLandlordMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSimpleLandlordMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SimpleLandlordMode::shouldRedirectToSimple($request)) {
            return redirect()->route('filament.landlord.pages.simple');
        }

        if (SimpleLandlordMode::shouldAutoSwitchToSimple($request)) {
            $request->session()->put('mobile_simple_mode_suggested', true);

            return redirect()->route('filament.landlord.pages.simple');
        }

        // Landing back on Simple Mode itself (not just passing through, e.g. a
        // full-mode "Back to Simple Mode" action) ends the escape window, so
        // the lock re-engages instead of silently letting the rest of that
        // 20-minute window bypass it on some other page.
        $panel = LandlordPanelProvider::PATH;
        if ($request->is($panel.'/simple', $panel.'/simple/*')) {
            SimpleLandlordMode::clearEscape($request);
        }

        return $next($request);
    }
}
