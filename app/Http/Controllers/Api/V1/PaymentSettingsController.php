<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use App\Payments\PaymentCredentials;
use Illuminate\Http\Request;

class PaymentSettingsController extends Controller
{
    public function __construct(private PaymentCredentials $credentials) {}

    private function settings(): PaymentSetting
    {
        // Scoped to the current store via the BelongsToStore global scope;
        // store_id is auto-filled on create. (Previously pinned to id=1, which
        // would have collided across stores.)
        return PaymentSetting::firstOrCreate([], [
            'cash_enabled' => true,
            'stripe_enabled' => false,
            'paypal_enabled' => false,
            'square_enabled' => false,
        ]);
    }

    /**
     * Admin: view settings. Secret credentials are hidden by the model; public
     * identifiers and `*_configured` flags are returned so the form can show
     * what's set without exposing the secrets.
     */
    public function show()
    {
        $settings = $this->settings();

        return response()->json([
            'data' => $settings,
            'webhook_urls' => $this->webhookUrls(),
        ]);
    }

    /**
     * Per-store webhook URLs for each provider's dashboard.
     *
     * @return array<string, string>
     */
    private function webhookUrls(): array
    {
        $slug = app(\App\Support\Tenancy\CurrentStore::class)->get()?->slug;

        if (! $slug) {
            return [];
        }

        return [
            'stripe' => url("/api/v1/webhooks/stripe/{$slug}"),
            'paypal' => url("/api/v1/webhooks/paypal/{$slug}"),
            'square' => url("/api/v1/webhooks/square/{$slug}"),
        ];
    }

    /**
     * Admin: update toggles and per-store gateway credentials.
     *
     * Blank/omitted credential fields are left unchanged so re-saving the form
     * never wipes a stored secret the admin can't see. Toggles always apply.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'cash_enabled' => 'sometimes|boolean',
            'stripe_enabled' => 'sometimes|boolean',
            'paypal_enabled' => 'sometimes|boolean',
            'square_enabled' => 'sometimes|boolean',

            'stripe_publishable_key' => 'sometimes|nullable|string|max:1000',
            'stripe_secret_key' => 'sometimes|nullable|string|max:1000',
            'stripe_webhook_secret' => 'sometimes|nullable|string|max:1000',

            'paypal_client_id' => 'sometimes|nullable|string|max:1000',
            'paypal_secret' => 'sometimes|nullable|string|max:1000',
            'paypal_mode' => 'sometimes|nullable|in:sandbox,live',
            'paypal_webhook_id' => 'sometimes|nullable|string|max:1000',

            'square_application_id' => 'sometimes|nullable|string|max:1000',
            'square_access_token' => 'sometimes|nullable|string|max:1000',
            'square_location_id' => 'sometimes|nullable|string|max:1000',
            'square_webhook_secret' => 'sometimes|nullable|string|max:1000',
            'square_mode' => 'sometimes|nullable|in:sandbox,live',
        ]);

        $toggleKeys = ['cash_enabled', 'stripe_enabled', 'paypal_enabled', 'square_enabled'];

        $payload = collect($validated)
            // Credential fields: drop blanks so they're left unchanged.
            ->reject(fn ($value, $key) => ! in_array($key, $toggleKeys, true) && blank($value))
            ->all();

        $settings = $this->settings();
        $settings->update($payload);

        return response()->json([
            'message' => 'Payment settings updated successfully',
            'data' => $settings->fresh(),
        ]);
    }

    /**
     * Public: list available methods + PUBLIC config needed by frontend
     * (publishable/client/application IDs only, never secrets)
     */
    public function methods()
    {
        $settings = $this->settings();

        // Only expose what frontend needs to initialize SDKs
        $methods = [];

        if ($settings->cash_enabled) {
            $methods[] = [
                'id' => 'cash',
                'name' => 'Cash Payment',
                'description' => 'Pay with cash on delivery or pickup',
                'icon' => 'cash',
            ];
        }

        if ($settings->stripe_enabled) {
            $publishable = $this->credentials->get('stripe', 'publishable_key');

            // Optional: if missing, don't advertise Stripe
            if ($publishable) {
                $methods[] = [
                    'id' => 'stripe',
                    'name' => 'Credit/Debit Card',
                    'description' => 'Pay securely with Stripe',
                    'icon' => 'stripe',
                    'config' => [
                        'publishable_key' => $publishable,
                    ],
                ];
            }
        }

        if ($settings->paypal_enabled) {
            $clientId = $this->credentials->get('paypal', 'client_id');

            if ($clientId) {
                $methods[] = [
                    'id' => 'paypal',
                    'name' => 'PayPal',
                    'description' => 'Pay with your PayPal account',
                    'icon' => 'paypal',
                    'config' => [
                        'client_id' => $clientId,
                    ],
                ];
            }
        }

        if ($settings->square_enabled) {
            $appId = $this->credentials->get('square', 'application_id');
            $locationId = $this->credentials->get('square', 'location_id');

            if ($appId && $locationId) {
                $methods[] = [
                    'id' => 'square',
                    'name' => 'Square',
                    'description' => 'Pay with Square',
                    'icon' => 'square',
                    'config' => [
                        'application_id' => $appId,
                        'location_id' => $locationId,
                    ],
                ];
            }
        }

        return response()->json(['data' => $methods]);
    }
}
