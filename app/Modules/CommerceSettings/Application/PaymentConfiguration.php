<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\PaymentSetting;
use App\Payments\PaymentCredentials;
use App\Support\Tenancy\CurrentStore;

class PaymentConfiguration
{
    public function __construct(
        private readonly PaymentCredentials $credentials,
        private readonly CurrentStore $currentStore,
    ) {}

    public function settings(): PaymentSetting
    {
        return PaymentSetting::firstOrCreate([], [
            'stripe_enabled' => false,
            'paypal_enabled' => false,
            'square_enabled' => false,
        ]);
    }

    public function update(array $attributes): PaymentSetting
    {
        $toggles = ['stripe_enabled', 'paypal_enabled', 'square_enabled'];
        $payload = collect($attributes)
            ->reject(fn ($value, $key) => ! in_array($key, $toggles, true) && blank($value))
            ->all();

        $settings = $this->settings();
        $settings->update($payload);

        return $settings->fresh();
    }

    /** @return array<string, string> */
    public function webhookUrls(): array
    {
        $slug = $this->currentStore->get()?->slug;

        return $slug ? [
            'stripe' => url("/api/v1/webhooks/stripe/{$slug}"),
            'paypal' => url("/api/v1/webhooks/paypal/{$slug}"),
            'square' => url("/api/v1/webhooks/square/{$slug}"),
        ] : [];
    }

    public function methods(): array
    {
        $settings = $this->settings();
        $methods = [];

        if ($settings->stripe_enabled && ($key = $this->credentials->get('stripe', 'publishable_key'))) {
            $methods[] = [
                'id' => 'stripe', 'name' => 'Credit/Debit Card',
                'description' => 'Pay securely with Stripe', 'icon' => 'stripe',
                'config' => ['publishable_key' => $key],
            ];
        }

        if ($settings->paypal_enabled && ($id = $this->credentials->get('paypal', 'client_id'))) {
            $methods[] = [
                'id' => 'paypal', 'name' => 'PayPal',
                'description' => 'Pay with your PayPal account', 'icon' => 'paypal',
                'config' => ['client_id' => $id],
            ];
        }

        $appId = $this->credentials->get('square', 'application_id');
        $locationId = $this->credentials->get('square', 'location_id');
        if ($settings->square_enabled && $appId && $locationId) {
            $methods[] = [
                'id' => 'square', 'name' => 'Square',
                'description' => 'Pay with Square', 'icon' => 'square',
                'config' => ['application_id' => $appId, 'location_id' => $locationId],
            ];
        }

        return $methods;
    }
}
