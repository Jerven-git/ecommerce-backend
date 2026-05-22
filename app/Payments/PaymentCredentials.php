<?php

namespace App\Payments;

use App\Models\PaymentSetting;
use App\Support\Tenancy\CurrentStore;

/**
 * Resolves payment-gateway credentials for the current store.
 *
 * Each field comes from the store's PaymentSetting row when set, otherwise it
 * falls back to the global config/payment.php (env) value — so the default
 * store keeps working with zero data migration, and a store that fills in only
 * some fields inherits the rest. This is the single source every gateway,
 * token client, and webhook verifier reads instead of config('payment.*').
 */
class PaymentCredentials
{
    /** @var array<int|string, PaymentSetting|null> */
    protected array $cache = [];

    public function __construct(protected CurrentStore $currentStore) {}

    /**
     * @return array<string, string|null>
     */
    public function resolve(string $provider): array
    {
        $settings = $this->settings();

        return match ($provider) {
            'stripe' => [
                'publishable_key' => $this->pick($settings?->stripe_publishable_key, 'payment.stripe.publishable_key'),
                'secret_key' => $this->pick($settings?->stripe_secret_key, 'payment.stripe.secret_key'),
                'webhook_secret' => $this->pick($settings?->stripe_webhook_secret, 'payment.stripe.webhook_secret'),
            ],
            'paypal' => [
                'client_id' => $this->pick($settings?->paypal_client_id, 'payment.paypal.client_id'),
                'secret' => $this->pick($settings?->paypal_secret, 'payment.paypal.secret'),
                'mode' => $this->pick($settings?->paypal_mode, 'payment.paypal.mode'),
                'webhook_id' => $this->pick($settings?->paypal_webhook_id, 'payment.paypal.webhook_id'),
            ],
            'square' => [
                'application_id' => $this->pick($settings?->square_application_id, 'payment.square.application_id'),
                'access_token' => $this->pick($settings?->square_access_token, 'payment.square.access_token'),
                'location_id' => $this->pick($settings?->square_location_id, 'payment.square.location_id'),
                'webhook_secret' => $this->pick($settings?->square_webhook_secret, 'payment.square.webhook_secret'),
                'mode' => $this->pick($settings?->square_mode, 'payment.square.mode'),
            ],
            default => [],
        };
    }

    public function get(string $provider, string $key): ?string
    {
        return $this->resolve($provider)[$key] ?? null;
    }

    protected function pick(?string $storeValue, string $configKey): ?string
    {
        return filled($storeValue) ? $storeValue : config($configKey);
    }

    protected function settings(): ?PaymentSetting
    {
        $storeId = $this->currentStore->id() ?? 'none';

        return $this->cache[$storeId] ??= PaymentSetting::first();
    }
}
