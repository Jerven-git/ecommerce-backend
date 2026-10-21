<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CommerceSettings\Application\PaymentConfiguration;
use Illuminate\Http\Request;

class PaymentSettingsController extends Controller
{
    public function __construct(private readonly PaymentConfiguration $payments) {}

    /**
     * Admin: view settings. Secret credentials are hidden by the model; public
     * identifiers and `*_configured` flags are returned so the form can show
     * what's set without exposing the secrets.
     */
    public function show()
    {
        $settings = $this->payments->settings();

        return response()->json([
            'data' => $settings,
            'webhook_urls' => $this->payments->webhookUrls(),
        ]);
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

        $settings = $this->payments->update($validated);

        return response()->json([
            'message' => 'Payment settings updated successfully',
            'data' => $settings,
        ]);
    }

    /**
     * Public: list available methods + PUBLIC config needed by frontend
     * (publishable/client/application IDs only, never secrets)
     */
    public function methods()
    {
        return response()->json(['data' => $this->payments->methods()]);
    }
}
