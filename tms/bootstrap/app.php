<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Security\SecurityHeaders;
use App\Modules\Core\Tenancy\ResolveTenant;
use App\Modules\Core\Users\EnsureTwoFactorEnrolled;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        // Tenant first: session, authentication and everything after it run
        // inside the resolved tenant (the user is only loaded afterwards).
        $middleware->web(prepend: [ResolveTenant::class]);
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: ResolveTenant::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Every web route, so a new route can never skip mandatory 2FA.
            EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
