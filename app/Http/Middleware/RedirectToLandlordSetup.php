<?php

namespace App\Http\Middleware;

use App\Filament\Pages\LandlordSetup;
use App\Providers\Filament\LandlordPanelProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a self-registered landlord who hasn't finished the setup wizard back
 * to /app/setup — at the step they left off (saved on LandlordProfile).
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

        if ($request->is(LandlordPanelProvider::PATH.'/setup')) {
            return $next($request);
        }

        return redirect()->route('filament.landlord.pages.setup');
    }
}
