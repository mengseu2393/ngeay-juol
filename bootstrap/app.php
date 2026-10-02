<?php

use App\Http\Middleware\SetLocale;
use App\Services\ErrorLogService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        // /admin → Error Log (ErrorLogService). report() only sees reportable
        // exceptions — validation/HTTP ones are in the default dontReport list —
        // so those two are caught at render time instead. Each callback returns
        // nothing, so Laravel's normal logging/rendering still runs.
        $exceptions->report(function (Throwable $e) {
            ErrorLogService::recordException($e);
        });

        // Livewire/Filament validation never reaches the handler (Livewire shows it
        // inline); this is plain HTTP forms — register, login, the tenant portal.
        $exceptions->render(function (ValidationException $e, Request $request) {
            ErrorLogService::recordValidation($e, $request);
        });

        $exceptions->render(function (HttpExceptionInterface $e) {
            if (in_array($e->getStatusCode(), [419, 429], true)) {
                ErrorLogService::recordBlocked($e);
            }
        });
    })->create();
