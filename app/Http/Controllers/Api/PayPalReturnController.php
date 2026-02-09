<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\PayPalToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class PayPalReturnController extends Controller
{
    public function return(Request $request, PayPalToken $token)
    {
        // PayPal sends order id as `token`
        $paypalOrderId = (string) $request->query('token');

        if (!$paypalOrderId) {
            return redirect($this->frontendUrl('/checkout/failed?reason=missing_token'));
        }

        // Find the Payment created during /orders/{order}/pay
        $payment = Payment::where('provider', 'paypal')
            ->where('provider_ref', $paypalOrderId)
            ->latest()
            ->first();

        if (!$payment) {
            return redirect($this->frontendUrl('/checkout/failed?reason=payment_not_found'));
        }

        // Idempotency: if already paid, just send user to success
        if ($payment->status === 'paid') {
            return redirect($this->frontendUrl('/checkout/complete?payment_id=' . $payment->id));
        }

        $base = config('payment.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        try {
            $accessToken = $token->get();

            /** @var Response $res */
            $res = Http::withToken($accessToken)
                ->connectTimeout(3)
                ->timeout(15)
                ->retry(2, 200)
                ->post($base . "/v2/checkout/orders/{$paypalOrderId}/capture");

            if (!$res->ok()) {
                // Mark failed (optional; some prefer keep pending)
                $payment->update([
                    'status' => 'failed',
                    'meta' => array_merge($payment->meta ?? [], [
                        'paypal_capture_error' => $res->json() ?? $res->body(),
                    ]),
                ]);

                return redirect($this->frontendUrl('/checkout/failed?payment_id=' . $payment->id));
            }

            $data = $res->json();

            // Optional: extract capture id
            $captureId = data_get($data, 'purchase_units.0.payments.captures.0.id');

            $payment->update([
                'status' => 'paid',
                'meta' => array_merge($payment->meta ?? [], [
                    'paypal_capture' => $data,
                    'capture_id' => $captureId,
                ]),
            ]);

            return redirect($this->frontendUrl('/checkout/complete?payment_id=' . $payment->id));
        } catch (\Throwable $e) {
            return redirect($this->frontendUrl('/checkout/failed?payment_id=' . $payment->id));
        }
    }

    public function cancel(Request $request)
    {
        // You can optionally find payment by token if you want.
        return redirect($this->frontendUrl('/checkout/cancelled'));
    }

    private function frontendUrl(string $path): string
    {
        // Set this to your Nuxt base URL, e.g. https://your-frontend.com
        $base = rtrim(config('app.frontend_url', config('app.url')), '/');
        return $base . $path;
    }
}
