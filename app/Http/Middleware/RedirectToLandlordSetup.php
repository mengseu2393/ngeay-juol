<?php

namespace App\Http\Middleware;

use App\Filament\Pages\LandlordSetup;
use App\Providers\Filament\LandlordPanelProvider;
use App\Support\M2Shell;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a self-registered landlord who hasn't finished the setup wizard back
 * to it — at the step they left off (saved on LandlordProfile): /m2/setup on a
 * phone or in /m2 (M2Shell::runsSetupFor), /app/setup otherwise.
 * Staff-provisioned landlords have no onboarding_step and are never touched.
 * "Finish later" (LandlordSetup::skip()) switches this off for the session.
 */
class RedirectToLandlordSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() || $request->session()->get(LandlordSetup::SKIP_SESSION_KEY)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user?->hasRole('landlord') || ! $user->landlordProfile?->needsOnboarding()) {
            return $next($request);
        }

        if ($request->is(LandlordPanelProvider::PATH.'/setup', 'm2/setup')) {
            return $next($request);
        }

        return redirect()->route(M2Shell::runsSetupFor($request) ? 'm2.setup' : 'filament.landlord.pages.setup');
    }
}
