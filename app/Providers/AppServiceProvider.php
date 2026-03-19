<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Payments\GatewayManager;
use App\Payments\Gateways\StripeGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\Gateways\SquareGateway;
use App\Payments\Contracts\PaymentServiceInterface;
use App\Payments\PaymentService;
use App\Services\SmsService;
use App\Events\PaymentConfirmed;
use App\Events\OrderRequiresRefund;
use App\Listeners\UpdateOrderStatus;
use App\Listeners\DeductStock;
use App\Listeners\HandleFailedOrder;
use App\Listeners\FulfillBackorder;
use App\Listeners\SendOrderConfirmation;
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

        $this->app->bind(PaymentServiceInterface::class, PaymentService::class);

        $this->app->singleton(SmsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(PaymentConfirmed::class, UpdateOrderStatus::class);
        Event::listen(PaymentConfirmed::class, DeductStock::class);
        Event::listen(PaymentConfirmed::class, FulfillBackorder::class);
        Event::listen(PaymentConfirmed::class, SendOrderConfirmation::class);
        Event::listen(OrderRequiresRefund::class, HandleFailedOrder::class);

        // GLOBAL API: cap total requests per IP across all endpoints
        // Prevents volumetric DDoS from a single source
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip())->response(function () {
                return response()->json([
                    'message' => 'Too many requests. Please slow down.',
                ], 429);
            });
        });

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

        // CONTACT FORM: prevent spam submissions
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(3)->by('contact:' . $request->ip());
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

        // PAYMENT SHOW: prevent payment ID enumeration
        RateLimiter::for('payment-show', function (Request $request) {
            return Limit::perMinute(15)->by('payment-show:' . $request->ip());
        });

        // BACKORDER TOKEN: prevent token brute force
        RateLimiter::for('backorder-token', function (Request $request) {
            return Limit::perMinute(10)->by('backorder-token:' . $request->ip());
        });

        // TRACKING: prevent tracking number enumeration
        RateLimiter::for('tracking', function (Request $request) {
            return Limit::perMinute(15)->by('tracking:' . $request->ip());
        });

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = config('app.frontend_url');
            $email = $notifiable->getEmailForPasswordReset();

            return "{$frontend}/admin/reset-password?token={$token}&email=" . urlencode($email);
        });
    }
}
