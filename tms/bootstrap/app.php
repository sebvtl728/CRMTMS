<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Security\EnforceIdleTimeout;
use App\Modules\Core\Security\SecurityHeaders;
use App\Modules\Core\Tenancy\ResolveTenant;
use App\Modules\Core\Users\EnsureTwoFactorEnrolled;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::group([], base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind Caddy only: X-Forwarded-* is honoured when the request comes from one of
        // TRUSTED_PROXIES (comma-separated IPs/CIDRs, empty = nobody). Never '*'.
        // HTTPS detection (HSTS) and the client IP (/health throttle) depend on it.
        // Read from the process environment: the middleware runs before config is loaded.
        $middleware->trustProxies(
            // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
            at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->append(SecurityHeaders::class);

        // Tenant first: session, authentication and everything after it run
        // inside the resolved tenant (the user is only loaded afterwards).
        $middleware->web(prepend: [ResolveTenant::class]);
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: ResolveTenant::class);

        $middleware->web(append: [
            EnforceIdleTimeout::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Every web route, so a new route can never skip mandatory 2FA.
            EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
