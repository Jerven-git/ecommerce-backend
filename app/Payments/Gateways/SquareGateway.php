<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SquareGateway implements PaymentGateway
{
    public function key(): string { return 'square'; }

    private function baseUrl(): string
    {
        return config('payment.square.mode') === 'live'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function client()
    {
        return Http::withToken(config('payment.square.access_token'))
            ->acceptJson();
    }

    public function createPayment(Order $order, array $meta = []): array
    {
        $idempotencyKey = (string) Str::uuid();
        
        /** @var Response $res */
        $res = $this->client()->post($this->baseUrl() . '/v2/online-checkout/payment-links', [
            'idempotency_key' => $idempotencyKey,
            'quick_pay' => [
                'name' => "Order #{$order->id}",
                'price_money' => [
                    'amount' => (int) round($order->total_amount * 100),
                    'currency' => strtoupper($order->currency ?? 'USD'),
                ],
                'location_id' => config('payment.square.location_id'),
            ],
            'checkout_options' => [
                'redirect_url' => config('app.url') . '/square/return',
            ],
        ]);

        $res->throw();
        $data = $res->json();

        $paymentLinkId = $data['payment_link']['id'] ?? null;
        $squareOrderId = $data['payment_link']['order_id'] ?? null;
        $approvalUrl   = $data['payment_link']['url'] ?? null;

        return [
            'provider' => 'square',
            'type' => 'redirect',
            'provider_ref' => $squareOrderId,
            'approval_url' => $approvalUrl,

            // optional extras stored in meta
            'square_payment_link_id' => $paymentLinkId,
            'square_order_id' => $squareOrderId,
        ];
    }

    public function parseWebhook(array $event): array
    {
        $type    = $event['type'] ?? null;
        $object  = $event['data']['object'] ?? [];
        $payment = $object['payment'] ?? null;

        $eventId = $event['event_id'] ?? null;

        // Prefer matching using Square order id (because you stored it as provider_ref)
        $squareOrderId = is_array($payment) ? ($payment['order_id'] ?? null) : null;

        $paymentId = is_array($payment) ? ($payment['id'] ?? null) : null;
        $paymentStatus = is_array($payment) ? ($payment['status'] ?? null) : null;

        $status = match ($type) {
            'payment.updated', 'payment.created' =>
                ($paymentStatus === 'COMPLETED' ? 'paid' : 'pending'),
            default => 'pending',
        };

        return [
            'provider'     => 'square',
            'event_id'     => $eventId,
            'event_type'   => $type,
            'order_id'     => null,                 // optional: only if you embed your internal id somewhere
            'provider_ref' => $squareOrderId,       // THIS should match payments.provider_ref
            'status'       => $status,
            'payload'      => $event,
            'meta' => [
                'square_payment_id' => $paymentId,
                'square_status'     => $paymentStatus,
            ],
        ];
    }
}