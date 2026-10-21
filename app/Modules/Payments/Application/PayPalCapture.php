<?php

namespace App\Modules\Payments\Application;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Payments\PaymentCredentials;
use App\Payments\PaymentService;
use App\Payments\PayPalToken;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PayPalCapture
{
    public function __construct(
        private readonly PaymentCredentials $credentials,
        private readonly PayPalToken $token,
        private readonly PaymentService $payments,
        private readonly CurrentStore $currentStore,
    ) {}

    public function find(string $paypalOrderId): ?Payment
    {
        return Payment::query()
            ->where('provider', 'paypal')
            ->where('provider_ref', $paypalOrderId)
            ->latest()
            ->first();
    }

    public function findOrFail(string $paypalOrderId): Payment
    {
        return Payment::query()
            ->where('provider', 'paypal')
            ->where('provider_ref', $paypalOrderId)
            ->latest()
            ->firstOrFail();
    }

    public function captureForApi(Payment $payment): PayPalCaptureResult
    {
        if ($payment->status === 'paid') {
            return new PayPalCaptureResult(
                true,
                true,
                data_get($payment->meta, 'capture_id'),
            );
        }

        $this->pinStore($payment);
        $response = $this->client()->timeout(20)->post(
            $this->captureUrl($payment),
            (object) [],
        );

        if (! $response->successful()) {
            $error = $response->json() ?? $response->body();
            $payment->update([
                'status' => 'pending',
                'meta' => array_merge($payment->meta ?? [], ['paypal_capture_error' => $error]),
            ]);

            return new PayPalCaptureResult(false, false, payload: $error, httpStatus: $response->status());
        }

        $result = $this->storeCapture($payment, $response);
        if ($result->completed) {
            $this->payments->markPaid($payment);
        }

        return $result;
    }

    public function captureForReturn(Payment $payment): PayPalCaptureResult
    {
        $this->pinStore($payment);

        $accessToken = $this->token->get();
        if (! is_string($accessToken) || trim($accessToken) === '') {
            return new PayPalCaptureResult(false, false);
        }

        $response = $this->retryingClient($accessToken)->post(
            $this->captureUrl($payment),
            (object) [],
        );

        if (! $response->successful()) {
            return new PayPalCaptureResult(
                false,
                false,
                payload: $response->json() ?? $response->body(),
                httpStatus: $response->status(),
            );
        }

        $result = $this->storeCapture($payment, $response);

        // Preserve the existing browser-return behavior: any successful PayPal
        // capture response finalizes the payment; provider webhooks remain the
        // fallback confirmation path.
        $this->payments->markPaid($payment);

        return $result;
    }

    private function pinStore(Payment $payment): void
    {
        if (! $payment->order_id) {
            return;
        }

        $order = Order::withoutGlobalScope(StoreScope::class)->find($payment->order_id);
        if ($order?->store_id && ($store = Store::find($order->store_id))) {
            $this->currentStore->set($store);
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token->get())
            ->acceptJson()
            ->asJson();
    }

    private function retryingClient(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
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
            });
    }

    private function captureUrl(Payment $payment): string
    {
        $baseUrl = $this->credentials->get('paypal', 'mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        return $baseUrl.'/v2/checkout/orders/'.$payment->provider_ref.'/capture';
    }

    private function storeCapture(Payment $payment, Response $response): PayPalCaptureResult
    {
        $payload = $response->json();
        $orderStatus = data_get($payload, 'status');
        $captureStatus = data_get($payload, 'purchase_units.0.payments.captures.0.status');
        $captureId = data_get($payload, 'purchase_units.0.payments.captures.0.id');

        $payment->update([
            'meta' => array_merge($payment->meta ?? [], [
                'paypal_capture' => $payload,
                'capture_id' => $captureId,
            ]),
        ]);

        return new PayPalCaptureResult(
            true,
            $orderStatus === 'COMPLETED' || $captureStatus === 'COMPLETED',
            $captureId,
            $payload,
            $response->status(),
        );
    }
}
