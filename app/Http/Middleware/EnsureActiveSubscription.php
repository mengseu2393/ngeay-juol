<?php

namespace App\Http\Middleware;

use App\Enums\SubscriptionAccess;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * Enforce subscription access on the landlord panel.
     * Staff (super_admin / support) bypass this check entirely.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Staff bypass — they need unfettered access to support landlords.
        if ($user->isPlatformStaff()) {
            return $next($request);
        }

        $access = SubscriptionService::effectiveAccess($user);

        // Stash the access level for the panel rendering layer.
        $request->attributes->set('_subscription_access', $access);

        if ($access === SubscriptionAccess::Revoked) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to('/landlord/login')
                ->with('error', __('Your subscription has expired. Please contact the administrator to restore access.'));
        }

        return $next($request);
    }
}
