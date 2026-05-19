<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ResolveAdminStore;
use App\Http\Middleware\ResolveStorefrontStore;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        RouteServiceProvider::class,
        \App\Modules\Realtime\Providers\RealtimeServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SanitizeInput::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);

        $middleware->api(append: [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'super_admin' => SuperAdminMiddleware::class,
            'tenant' => ResolveAdminStore::class,
            'storefront' => ResolveStorefrontStore::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'session.lifetime' => SessionLifetimeMiddleware::class,
        ]);

        $middleware->encryptCookies(except: []);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})->create();
