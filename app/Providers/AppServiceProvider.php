<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Payments\GatewayManager;
use App\Payments\Gateways\StripeGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\Gateways\SquareGateway;
use Stripe\StripeClient;

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
        //
    }
}
