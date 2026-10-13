<?php

namespace App\Http\Middleware;

use App\Support\MobileShell;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /m exists only for users the feature flag lets in (App\Support\MobileShell);
 * for everyone else it 404s, as if it weren't there. Also registered as
 * Livewire persistent middleware, so turning the flag off stops the screens'
 * own requests too, not just page loads.
 */
class EnsureMobileShellAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(MobileShell::isAvailableFor($request->user()), 404);

        return $next($request);
    }
}
