<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\Contracts\PaymentGateway;
use Stripe\StripeClient;

class StripeGateway implements PaymentGateway
{
    public function __construct(private StripeClient $stripe) {}

    public function key(): string { return 'stripe'; }

    public function createPayment(Order $order, array $meta = []): array
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => (int) round($order->total_amount * 100),
            'currency' => strtolower($order->currency ?? 'usd'),
            'metadata' => [
                'order_id' => (string) $order->id,
                ...$meta,
            ],
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return [
            'provider' => 'stripe',
            'type' => 'payment_intent',
            'provider_ref' => $intent->id,
            'client_secret' => $intent->client_secret,
        ];
    }

    public function parseWebhook(array $event): array
    {
        $type = $event['type'] ?? null;
        $obj  = $event['data']['object'] ?? [];
        $orderId = $obj['metadata']['order_id'] ?? null;

        $status = match ($type) {
            'payment_intent.succeeded' => 'paid',
            'payment_intent.payment_failed' => 'failed',
            default => 'pending',
        };

        return [
            'provider' => 'stripe',
            'event_id' => $event['id'] ?? null,
            'event_type' => $type,
            'order_id' => $orderId,
            'provider_ref' => $obj['id'] ?? null,
            'status' => $status,
            'payload' => $event,
        ];
    }
}
