<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use Illuminate\Http\Request;

class PaymentSettingsController extends Controller
{
    private function settings(): PaymentSetting
    {
        return PaymentSetting::firstOrCreate(['id' => 1], []);
    }

    /**
     * Admin: view settings (NO KEYS IN DB, so safe to return)
     */
    public function show()
    {
        $settings = $this->settings();

        return response()->json([
            'data' => $settings,
        ]);
    }

    /**
     * Admin: update toggles only (enable/disable + test mode)
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'cash_enabled' => 'sometimes|boolean',
            'stripe_enabled' => 'sometimes|boolean',
            'paypal_enabled' => 'sometimes|boolean',
            'square_enabled' => 'sometimes|boolean',
        ]);

        $settings = $this->settings();
        $settings->update($validated);

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
            $publishable = config('payment.stripe.publishable_key');

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
            $clientId = config('payment.paypal.client_id');

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
            $appId = config('payment.square.application_id');
            $locationId = config('payment.square.location_id');

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
