<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
        ]);

        // Behind a TLS-terminating proxy, url()/asset() emit http:// and the
        // login rate limiter keys on the proxy's IP instead of the client's,
        // unless the proxy is trusted. Set TRUSTED_PROXIES to the load
        // balancer's IP/CIDR (comma-separated, or '*' when it is the only
        // ingress). Deliberately empty by default: trusting an unknown proxy
        // lets a spoofed X-Forwarded-For header defeat that same throttle.
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
