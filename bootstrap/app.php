<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\ResolveAdminStore;
use App\Http\Middleware\ResolveStorefrontStore;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SubscriptionMiddleware;
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
        // The app always sits behind a reverse proxy (Caddy → nginx in prod,
        // nginx in dev). Trust it so Laravel honours X-Forwarded-Proto/Host and
        // correctly detects HTTPS — required for secure cookies and URL
        // generation once Caddy terminates TLS for each custom domain.
        $middleware->trustProxies(at: '*');

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
            'account.active' => EnsureAccountActive::class,
            'super_admin' => SuperAdminMiddleware::class,
            'subscribed' => SubscriptionMiddleware::class,
            'tenant' => ResolveAdminStore::class,
            'storefront' => ResolveStorefrontStore::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'session.lifetime' => SessionLifetimeMiddleware::class,
        ]);

        $middleware->encryptCookies(except: []);

        // Sanctum validates CSRF for stateful browser requests. Exempt only
        // public/provider mutations that can legitimately begin without an SPA
        // session; authenticated admin and super-admin mutations stay covered.
        $middleware->validateCsrfTokens(except: [
            'api/forgot-password',
            'api/reset-password',
            'api/v1/contact',
            'api/v1/gift-cards/validate',
            'api/v1/commission-requests',
            'api/v1/subscribe',
            'api/v1/discounts/validate',
            'api/v1/shipping/calculate',
            'api/v1/tax/calculate',
            'api/v1/tax/calculate-cart',
            'api/v1/orders',
            'api/v1/orders/*/pay',
            'api/v1/orders/*/stripe/intent',
            'api/v1/paypal/capture',
            'api/v1/backorders/pay/*',
            'api/v1/webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})->create();
