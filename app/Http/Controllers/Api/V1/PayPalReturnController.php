<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Payments\PaymentCredentials;
use App\Payments\PaymentService;
use App\Payments\PayPalToken;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalReturnController extends Controller
{
    public function __construct(private PaymentCredentials $credentials) {}

    private function paypalBaseUrl(): string
    {
        return $this->credentials->get('paypal', 'mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Payment confirmations land back here from PayPal with an unreliable tenant
     * (the return URL host may not be the order's store). Pin CurrentStore to
     * the order's own store so credential resolution and the PaymentConfirmed
     * listeners operate under the right merchant.
     */
    private function pinStoreFromPayment(Payment $payment): void
    {
        if (! $payment->order_id) {
            return;
        }

        $order = Order::withoutGlobalScope(StoreScope::class)->find($payment->order_id);
        if ($order && $order->store_id) {
            $store = Store::find($order->store_id);
            if ($store) {
                app(CurrentStore::class)->set($store);
            }
        }
    }

    public function capture(Request $request, PayPalToken $token, PaymentService $payments)
    {
        $request->validate(['token' => ['required', 'string']]);
        $paypalOrderId = trim((string) $request->input('token'));

        $payment = Payment::where('provider', 'paypal')
            ->where('provider_ref', $paypalOrderId)
            ->latest()
            ->firstOrFail();

        // Idempotent
        if ($payment->status === 'paid') {
            return response()->json([
                'payment_id' => $payment->id,
                'status' => 'paid',
                'capture_id' => data_get($payment->meta, 'capture_id'),
            ]);
        }

        $this->pinStoreFromPayment($payment);

        $base = $this->paypalBaseUrl();

        $accessToken = $token->get();

        /** @var Response $res */
        $res = Http::withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            // PayPal expects {} (object) or no body — NOT []
            ->post($base."/v2/checkout/orders/{$paypalOrderId}/capture", (object) []);

        if (! $res->successful()) {
            $payment->update([
                'status' => 'pending',
                'meta' => array_merge($payment->meta ?? [], [
                    'paypal_capture_error' => $res->json() ?? $res->body(),
                ]),
            ]);

            return response()->json([
                'message' => 'PayPal capture failed',
                'payment_id' => $payment->id,
                'paypal_error' => $res->json() ?? $res->body(),
            ], 422);
        }

        $data = $res->json();

        $orderStatus = data_get($data, 'status');
        $captureStatus = data_get($data, 'purchase_units.0.payments.captures.0.status');
        $captureId = data_get($data, 'purchase_units.0.payments.captures.0.id');

        // Save capture payload for auditing/debugging
        $payment->update([
            'meta' => array_merge($payment->meta ?? [], [
                'paypal_capture' => $data,
                'capture_id' => $captureId,
            ]),
        ]);

        // If not completed, keep pending and let webhook retry/finalize later
        if ($orderStatus !== 'COMPLETED' && $captureStatus !== 'COMPLETED') {
            return response()->json([
                'message' => 'PayPal capture not completed',
                'payment_id' => $payment->id,
                'status' => 'pending',
                'paypal_status' => $orderStatus,
                'capture_status' => $captureStatus,
            ], 422);
        }

        // ✅ Finalize immediately: flips to paid + deducts stock + moves order
        $payments->markPaid($payment);

        return response()->json([
            'payment_id' => $payment->id,
            'status' => 'paid',
            'capture_id' => $captureId,
        ]);
    }

    public function return(Request $request, PayPalToken $token, PaymentService $payments)
    {
        $paypalOrderId = trim((string) $request->query('token'));

        if ($paypalOrderId === '') {
            return redirect($this->frontendUrl('/checkout/failed?reason=missing_token'));
        }

        $payment = Payment::where('provider', 'paypal')
            ->where('provider_ref', $paypalOrderId)
            ->latest()
            ->first();

        if (! $payment) {
            return redirect($this->frontendUrl('/checkout/failed?reason=payment_not_found'));
        }

        if ($payment->status === 'paid') {
            return redirect($this->frontendUrl('/payment/complete?payment_id='.$payment->id));
        }

        $this->pinStoreFromPayment($payment);

        $base = $this->paypalBaseUrl();

        try {
            $accessToken = $token->get();

            if (! is_string($accessToken) || trim($accessToken) === '') {
                return redirect($this->frontendUrl('/checkout/failed?payment_id='.$payment->id));
            }

            /** @var Response $res */
            $res = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->retry(2, 250, function ($exception, $request, $response) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($response instanceof Response) {
                        $status = $response->status();

                        return $status === 429 || ($status >= 500 && $status <= 599);
                    }

                    return false;
                })
                ->post($base."/v2/checkout/orders/{$paypalOrderId}/capture", (object) []);

            if (! $res->successful()) {
                // log useful info for debugging
                Log::warning('PayPal capture failed', [
                    'paypal_order_id' => $paypalOrderId,
                    'payment_id' => $payment->id,
                    'status' => $res->status(),
                    'body' => $res->json() ?? $res->body(),
                ]);

                // keep behavior: just redirect failed
                return redirect($this->frontendUrl('/checkout/failed?payment_id='.$payment->id));
            }

            $data = $res->json();
            $captureId = data_get($data, 'purchase_units.0.payments.captures.0.id');

            $payment->update([
                'meta' => array_merge($payment->meta ?? [], [
                    'paypal_capture' => $data,
                    'capture_id' => $captureId,
                ]),
            ]);

            $payments->markPaid($payment);

            return redirect($this->frontendUrl('/payment/complete?payment_id='.$payment->id));

        } catch (\Throwable $e) {
            // keep redirect behavior; just log for visibility
            Log::warning('PayPal return capture exception', [
                'paypal_order_id' => $paypalOrderId,
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect($this->frontendUrl('/checkout/failed?payment_id='.$payment->id));
        }
    }

    public function cancel(Request $request)
    {
        return redirect($this->frontendUrl('/checkout?cancelled=1'));
    }

    private function frontendUrl(string $path): string
    {
        $base = rtrim(
            config('app.frontend_url')
                ?: env('FRONTEND_URL')
                ?: config('app.url'),
            '/'
        );

        return $base.$path;
    }
}
