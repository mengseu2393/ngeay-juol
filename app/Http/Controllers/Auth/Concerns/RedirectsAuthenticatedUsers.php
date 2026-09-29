<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Providers\Filament\LandlordPanelProvider;
use App\Support\SimpleLandlordMode;

/**
 * Post-authentication panel routing, shared by every way into the app
 * (password login and QR login) so the two can never drift apart.
 */
trait RedirectsAuthenticatedUsers
{
    protected function redirectUser($user)
    {
        $intended = session()->get('url.intended');
        if ($intended) {
            $path = parse_url($intended, PHP_URL_PATH) ?: '';
            $path = '/'.ltrim($path, '/');

            if ($user->hasAnyRole(['landlord', 'landlord_manager'])) {
                if ($path === '/admin' || str_starts_with($path, '/admin/')) {
                    session()->forget('url.intended');
                }
            } elseif ($user->hasRole('tenant')) {
                $panel = '/'.LandlordPanelProvider::PATH;
                if ($path === '/admin' || str_starts_with($path, '/admin/') || $path === $panel || str_starts_with($path, $panel.'/')) {
                    session()->forget('url.intended');
                }
            }
        }

        if ($user->isPlatformStaff()) {
            return redirect()->intended(route('filament.admin.pages.dashboard'));
        }

        if ($user->hasAnyRole(['landlord', 'landlord_manager'])) {
            // Straight to Simple Mode when it's on: landing on /app first costs a whole
            // extra panel request just to be redirected here by RedirectToSimpleLandlordMode.
            return redirect()->intended(route(
                SimpleLandlordMode::enabledFor($user) ? 'filament.landlord.pages.simple' : 'filament.landlord.pages.dashboard'
            ));
        }

        if ($user->hasRole('tenant')) {
            return redirect()->intended(route('portal.dashboard'));
        }

        return redirect()->intended('/');
    }
}
