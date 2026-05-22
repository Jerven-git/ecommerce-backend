<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'cash_enabled',
        'stripe_enabled',
        'paypal_enabled',
        'square_enabled',
        'stripe_publishable_key',
        'stripe_secret_key',
        'stripe_webhook_secret',
        'paypal_client_id',
        'paypal_secret',
        'paypal_mode',
        'paypal_webhook_id',
        'square_application_id',
        'square_access_token',
        'square_location_id',
        'square_webhook_secret',
        'square_mode',
    ];

    protected $casts = [
        'cash_enabled' => 'boolean',
        'stripe_enabled' => 'boolean',
        'paypal_enabled' => 'boolean',
        'square_enabled' => 'boolean',
        'stripe_secret_key' => 'encrypted',
        'stripe_webhook_secret' => 'encrypted',
        'paypal_secret' => 'encrypted',
        'square_access_token' => 'encrypted',
        'square_webhook_secret' => 'encrypted',
    ];

    /**
     * Secret credentials must never be serialized into an API response. The
     * `*_configured` accessors below expose only whether each is set.
     *
     * @var list<string>
     */
    protected $hidden = [
        'stripe_secret_key',
        'stripe_webhook_secret',
        'paypal_secret',
        'square_access_token',
        'square_webhook_secret',
    ];

    /** @var list<string> */
    protected $appends = [
        'stripe_secret_key_configured',
        'stripe_webhook_secret_configured',
        'paypal_secret_configured',
        'square_access_token_configured',
        'square_webhook_secret_configured',
    ];

    public function getStripeSecretKeyConfiguredAttribute(): bool
    {
        return filled($this->stripe_secret_key);
    }

    public function getStripeWebhookSecretConfiguredAttribute(): bool
    {
        return filled($this->stripe_webhook_secret);
    }

    public function getPaypalSecretConfiguredAttribute(): bool
    {
        return filled($this->paypal_secret);
    }

    public function getSquareAccessTokenConfiguredAttribute(): bool
    {
        return filled($this->square_access_token);
    }

    public function getSquareWebhookSecretConfiguredAttribute(): bool
    {
        return filled($this->square_webhook_secret);
    }

    public function availableMethods(): array
    {
        $credentials = app(\App\Payments\PaymentCredentials::class);
        $methods = [];

        if ($this->cash_enabled) {
            $methods[] = [
                'id' => 'cash',
                'name' => 'Cash Payment',
            ];
        }

        if ($this->stripe_enabled) {
            $methods[] = [
                'id' => 'stripe',
                'name' => 'Credit/Debit Card',
                'config' => [
                    'publishable_key' => $credentials->get('stripe', 'publishable_key'),
                ],
            ];
        }

        if ($this->paypal_enabled) {
            $methods[] = [
                'id' => 'paypal',
                'name' => 'PayPal',
                'config' => [
                    'client_id' => $credentials->get('paypal', 'client_id'),
                ],
            ];
        }

        if ($this->square_enabled) {
            $methods[] = [
                'id' => 'square',
                'name' => 'Square',
                'config' => [
                    'application_id' => $credentials->get('square', 'application_id'),
                    'location_id' => $credentials->get('square', 'location_id'),
                ],
            ];
        }

        return $methods;
    }
}
