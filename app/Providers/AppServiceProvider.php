<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Payments\GatewayManager;
use App\Payments\Gateways\StripeGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\Gateways\SquareGateway;
use Stripe\StripeClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, function () {
            return new StripeClient(config('payment.stripe.secret_key'));
        });

        $this->app->singleton(GatewayManager::class, function ($app) {
            return new GatewayManager([
                'stripe' => $app->make(StripeGateway::class),
                'paypal' => $app->make(PayPalGateway::class),
                'square' => $app->make(SquareGateway::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // LOGIN: 5 attempts/min per email+ip (good default)
        // plus a longer window safety net to slow sustained attacks.
        RateLimiter::for('login', function (Request $request) {
            $email = (string) str($request->input('email', ''))->lower();
            $key = 'login:' . sha1($email . '|' . $request->ip());

            return [
                Limit::perMinute(5)->by($key)->response(function () use ($key) {
                    $retryAfter = RateLimiter::availableIn($key);
                    return response()->json([
                        'message' => 'Too many login attempts. Please try again later.',
                        'retry_after_seconds' => $retryAfter,
                    ], 429);
                }),

                Limit::perHour(20)->by($key . ':hour'),
            ];
        });

        // PAYMENT INTENT: allow some retries, but prevent abuse
        RateLimiter::for('stripe-intent', function (Request $request) {
            $userKey = $request->user()?->id
                ? 'user:' . $request->user()->id
                : 'ip:' . $request->ip();

            $orderId = (string) $request->route('order');
            $orderKey = $userKey . '|order:' . $orderId;

            return [
                Limit::perMinute(10)->by($orderKey),

                Limit::perSecond(10, 3)->by($orderKey . ':burst'),
            ];
        });

        // PAY: usually stricter than intent (charging endpoint)
        RateLimiter::for('order-pay', function (Request $request) {
            $userKey = $request->user()?->id
                ? 'user:' . $request->user()->id
                : 'ip:' . $request->ip();

            $orderId = (string) $request->route('order');
            $key = $userKey . '|order:' . $orderId;

            return [
                Limit::perMinute(5)->by($key),
                Limit::perSecond(10, 2)->by($key . ':burst'),
            ];
        });

        // ORDER CREATION: prevent spam orders from same IP
        RateLimiter::for('order-store', function (Request $request) {
            return Limit::perMinute(10)->by('order-store:' . $request->ip());
        });

        // DISCOUNT VALIDATE: prevent brute-forcing discount codes
        RateLimiter::for('discount-validate', function (Request $request) {
            return Limit::perMinute(15)->by('discount-validate:' . $request->ip());
        });

        // PAYPAL CAPTURE: prevent duplicate capture attempts
        RateLimiter::for('paypal-capture', function (Request $request) {
            return Limit::perMinute(10)->by('paypal-capture:' . $request->ip());
        });

        // PASSWORD RESET: prevent email enumeration and abuse
        RateLimiter::for('password-reset', function (Request $request) {
            $email = (string) str($request->input('email', ''))->lower();
            $key = 'password-reset:' . sha1($email . '|' . $request->ip());

            return Limit::perMinute(5)->by($key);
        });

        // TWO-FACTOR VERIFY: prevent brute-forcing the 6-digit code
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by('two-factor:' . $request->ip());
        });

        // TWO-FACTOR RESEND: prevent email spam
        RateLimiter::for('two-factor-resend', function (Request $request) {
            return Limit::perMinute(2)->by('two-factor-resend:' . $request->ip());
        });

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = config('app.frontend_url');
            $email = $notifiable->getEmailForPasswordReset();

            return "{$frontend}/admin/reset-password?token={$token}&email=" . urlencode($email);
        });
    }
}
