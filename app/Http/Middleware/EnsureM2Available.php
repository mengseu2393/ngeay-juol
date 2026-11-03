<?php

namespace App\Http\Middleware;

use App\Support\M2Shell;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /m2 exists only for users the feature flag lets in (App\Support\M2Shell); for
 * everyone else it 404s. Also Livewire persistent middleware, so switching the
 * flag off stops the screens' own requests too.
 */
class EnsureM2Available
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(M2Shell::isAvailableFor($request->user()), 404);

        return $next($request);
    }
}
