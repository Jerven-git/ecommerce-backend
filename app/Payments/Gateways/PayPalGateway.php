<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
class PayPalGateway implements PaymentGateway
{
    public function key(): string { return 'paypal'; }

    private function baseUrl(): string
    {
        return config('payment.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function token(): string
    {
        /** @var Response $res */
        $res = Http::asForm()
            ->withBasicAuth(config('payment.paypal.client_id'), config('payment.paypal.secret'))
            ->post($this->baseUrl() . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        $res->throw();
        return $res->json('access_token');
    }

    public function createPayment(Order $order, array $meta = []): array
    {
        $token = $this->token();

        $baseReturn = rtrim(
            env('PAYPAL_RETURN_BASE_URL', env('FRONTEND_URL', 'http://localhost:8000')),
            '/'
        );

        $currency = strtoupper($order->currency ?? 'USD');
        $value = number_format((float) $order->total_amount, 2, '.', '');

        // ✅ invoice_id must be unique per transaction
        $invoiceId = 'ORDER-' . $order->id . '-' . Str::uuid()->toString();

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id'  => (string) $order->id,  // internal order id (OK to repeat)
                'invoice_id' => $invoiceId,           // must be unique
                'amount' => [
                    'currency_code' => $currency,
                    'value' => $value,
                ],
            ]],
            'application_context' => [
                'return_url' => $baseReturn . '/payment/complete',
                'cancel_url' => $baseReturn . '/payment/cancelled',
            ],
        ];
        
        /** @var Response $res */
        $res = Http::withToken($token)
            ->timeout(20)
            ->retry(2, 200)
            ->post($this->baseUrl() . '/v2/checkout/orders', $payload);

        if (!$res->successful()) {
            throw new \RuntimeException("PayPal create order failed: {$res->status()} {$res->body()}");
        }

        $data = $res->json();

        $paypalOrderId = data_get($data, 'id');
        $approvalUrl = collect($data['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        if (!$paypalOrderId || !$approvalUrl) {
            throw new \RuntimeException("PayPal response missing fields: {$res->body()}");
        }

        return [
            'provider'      => 'paypal',
            'type'          => 'redirect',
            'provider_ref'  => $paypalOrderId,
            'approval_url'  => $approvalUrl,
            'redirect_url'  => $approvalUrl,
            'invoice_id'    => $invoiceId, // return the actual invoice id you used
        ];
    }

    public function parseWebhook(array $event): array
    {
        $type = $event['event_type'] ?? null;
        $resource = $event['resource'] ?? [];

        // IMPORTANT: for PAYMENT.CAPTURE.* events, this is the PayPal ORDER id
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        // Your internal order id from custom_id
        $orderId = $resource['custom_id'] ?? null;

        // Capture id (optional, useful for refunds / audit)
        $captureId = $resource['id'] ?? null;

        $status = match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            default => 'pending',
        };

        return [
            'provider' => 'paypal',
            'event_id' => $event['id'] ?? null,
            'event_type' => $type,
            'order_id' => $orderId,
            // Make provider_ref match what you stored in createPayment()
            'provider_ref' => $paypalOrderId ?? $resource['invoice_id'] ?? null,
            'status' => $status,
            'payload' => $event,
            'meta' => [
                'capture_id' => $captureId,
            ],
        ];
    }
}
