<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Contracts\ChecksPaymentStatus;
use App\Payments\Contracts\HandlesWebhooks;
use App\Payments\Contracts\PaymentGateway;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeGateway implements PaymentGateway, ChecksPaymentStatus, HandlesWebhooks
{
    public function __construct(private StripeClient $stripe) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function createPayment(Order $order, array $meta = []): array
    {
        $rawAmount = $meta['amount_override'] ?? $order->total_amount;
        $amount = $this->toCents($rawAmount);
        $currency = strtolower($order->currency ?: 'usd');

        // Idempotency prevents duplicate intents if your request retries
        $backorderId = $meta['backorder_id'] ?? null;
        $idempotencyKey = $backorderId
            ? "order:{$order->id}:backorder:{$backorderId}:create_intent"
            : "order:{$order->id}:create_intent";

        $intent = $this->stripe->paymentIntents->create(
            [
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => array_filter([
                    'order_id' => (string) $order->id,
                    'backorder_id' => $backorderId ? (string) $backorderId : null,
                ]),
                'automatic_payment_methods' => ['enabled' => true],
            ],
            [
                'idempotency_key' => $idempotencyKey,
            ]
        );

        return [
            'provider' => 'stripe',
            'type' => 'payment_intent',
            'provider_ref' => $intent->id,
            'client_secret' => $intent->client_secret,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    public function verifyPayment(Payment $payment): array
    {
        $piId = (string) ($payment->provider_ref ?? '');
        if ($piId === '') {
            return ['paid' => false, 'meta' => ['reason' => 'missing_provider_ref']];
        }

        try {
            $pi = $this->stripe->paymentIntents->retrieve($piId, []);
            $status = $pi->status ?? null;

            // Stripe statuses: requires_payment_method, requires_confirmation, requires_action,
            // processing, requires_capture, canceled, succeeded
            $paid = ($status === 'succeeded');
            $failed = in_array($status, ['canceled'], true);

            return [
                'paid' => $paid,
                'failed' => $failed,
                'meta' => [
                    'stripe_pi_status' => $status,
                ],
            ];
        } catch (ApiErrorException $e) {
            return [
                'paid' => false,
                'meta' => [
                    'reason' => 'stripe_api_error',
                    'stripe_error_type' => $e->getStripeCode() ?: $e->getError()?->type,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'paid' => false,
                'meta' => [
                    'reason' => 'exception',
                    'exception' => class_basename($e),
                ],
            ];
        }
    }

    public function parseWebhook(array $event): array
    {
        $type = $event['type'] ?? null;
        $obj  = $event['data']['object'] ?? [];

        $orderId = data_get($obj, 'metadata.order_id');

        $status = match ($type) {
            'payment_intent.succeeded'       => 'paid',
            'payment_intent.payment_failed'  => 'failed',
            'payment_intent.canceled'        => 'failed',
            default                          => 'pending',
        };

        return [
            'provider' => 'stripe',
            'event_id' => $event['id'] ?? null,
            'event_type' => $type,
            'order_id' => $orderId,
            'provider_ref' => $obj['id'] ?? null,
            'status' => $status,
            'payload' => $event,
            'meta' => [
                'stripe_pi_status' => $obj['status'] ?? null,
            ],
        ];
    }

    private function toCents($amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        return (int) str_replace('.', '', $normalized);
    }
}