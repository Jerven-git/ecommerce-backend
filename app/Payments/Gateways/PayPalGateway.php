<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Http;

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

        $baseReturn = rtrim(env('PAYPAL_RETURN_BASE_URL', env('PAYPAL_PUBLIC_URL', config('app.url'))), '/');

        /** @var Response $res */
        $res = Http::withToken($token)->post($this->baseUrl() . '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'custom_id'  => (string) ($meta['payment_id'] ?? $order->id),
            'invoice_id' => 'PAY-' . ($meta['payment_id'] ?? $order->id),
            'amount' => [
            'currency_code' => strtoupper($order->currency ?? 'USD'),
            'value' => number_format($order->total_amount, 2, '.', ''),
            ],
        ]],
        'application_context' => [
            'return_url' => $baseReturn . '/paypal/return',
            'cancel_url' => $baseReturn . '/paypal/cancel',
        ],
        ]);

        $res->throw();
        $data = $res->json();

        $approval = collect($data['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        return [
            'provider' => 'paypal',
            'type' => 'redirect',
            'provider_ref' => $data['id'] ?? null, // paypal order id
            'approval_url' => $approval,
            'invoice_id' => (string) $order->id,
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
