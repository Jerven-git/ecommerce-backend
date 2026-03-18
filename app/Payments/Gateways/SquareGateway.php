<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\ChecksPaymentStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SquareGateway implements PaymentGateway, ChecksPaymentStatus
{
    public function key(): string
    {
        return 'square';
    }

    private function baseUrl(): string
    {
        return config('payment.square.mode') === 'live'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function client(): PendingRequest
    {
        // Centralize HTTP policy: timeouts + accept json + auth
        return Http::withToken(config('payment.square.access_token'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10);
    }

    /**
     * Only retry on network errors + 429 + 5xx
     */
    private function withRetry(PendingRequest $req): PendingRequest
    {
        return $req->retry(
            2,
            200,
            function (\Throwable $e, ?Response $response) {
                if ($response === null) {
                    return true; // network error
                }

                $status = $response->status();
                return $status === 429 || $status >= 500;
            }
        );
    }

    public function createPayment(Order $order, array $meta = []): array
    {
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'idempotency_key' => $idempotencyKey,
            'quick_pay' => [
                'name' => "Order #{$order->id}",
                'price_money' => [
                    'amount' => $this->toCents($meta['amount_override'] ?? $order->total_amount),
                    'currency' => strtoupper($order->currency ?: 'USD'),
                ],
                'location_id' => config('payment.square.location_id'),
            ],
            'checkout_options' => [
                'redirect_url' => rtrim(config('app.url'), '/') . '/payment/complete',
            ],
        ];

        /** @var Response $res */
        $res = $this->withRetry($this->client())
            ->post($this->baseUrl() . '/v2/online-checkout/payment-links', $payload);

        $res->throw();

        $data = $res->json();

        $paymentLink = $data['payment_link'] ?? [];

        $paymentLinkId = $paymentLink['id'] ?? null;
        $squareOrderId = $paymentLink['order_id'] ?? null;
        $approvalUrl   = $paymentLink['url'] ?? null;

        return [
            'provider' => 'square',
            'type' => 'redirect',
            'provider_ref' => $squareOrderId,
            'approval_url' => $approvalUrl,

            // store extras in meta (non-secret only)
            'square_payment_link_id' => $paymentLinkId,
            'square_order_id' => $squareOrderId,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    public function verifyPayment(Payment $payment): array
    {
        $squareOrderId = (string) ($payment->provider_ref ?? '');

        if ($squareOrderId === '') {
            return ['paid' => false, 'meta' => ['reason' => 'missing_provider_ref']];
        }

        try {
            /** @var Response $orderRes */
            $orderRes = $this->withRetry($this->client())
                ->get($this->baseUrl() . "/v2/orders/{$squareOrderId}");

            if (!$orderRes->successful()) {
                return [
                    'paid' => false,
                    'meta' => [
                        'square_verify_order_http' => $orderRes->status(),
                    ],
                ];
            }

            $order = $orderRes->json('order') ?? [];
            $tenders = $order['tenders'] ?? [];

            $paymentIds = [];
            foreach ($tenders as $t) {
                $pid = $t['payment_id'] ?? null;
                if ($pid) {
                    $paymentIds[$pid] = true;
                }
            }
            $paymentIds = array_keys($paymentIds);

            // Tendors may show later
            if (empty($paymentIds)) {
                return [
                    'paid' => false,
                    'meta' => [
                        'square_order_state' => $order['state'] ?? null,
                        'reason' => 'no_tenders_yet',
                    ],
                ];
            }

            foreach ($paymentIds as $pid) {
                /** @var Response $payRes */
                $payRes = $this->withRetry($this->client())
                    ->get($this->baseUrl() . "/v2/payments/{$pid}");

                if (!$payRes->successful()) {
                    continue;
                }

                $p = $payRes->json('payment') ?? [];
                $status = $p['status'] ?? null;

                if ($status === 'COMPLETED') {
                    return [
                        'paid' => true,
                        'meta' => [
                            'square_payment_id' => $p['id'] ?? $pid,
                            'square_status' => $status,
                        ],
                    ];
                }

                if (in_array($status, ['FAILED', 'CANCELED'], true)) {
                    return [
                        'paid' => false,
                        'failed' => true,
                        'meta' => [
                            'square_payment_id' => $p['id'] ?? $pid,
                            'square_status' => $status,
                        ],
                    ];
                }
            }

            return [
                'paid' => false,
                'meta' => [
                    'square_payment_ids' => $paymentIds,
                    'reason' => 'no_completed_payment_found',
                ],
            ];
        } catch (\Throwable $e) {
            // Don’t swallow silently: return safe debug meta (no secrets)
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
        $type    = $event['type'] ?? null;
        $eventId = $event['event_id'] ?? null;

        $object  = $event['data']['object'] ?? [];
        $payment = $object['payment'] ?? null;

        $squareOrderId   = is_array($payment) ? ($payment['order_id'] ?? null) : null;
        $squarePaymentId = is_array($payment) ? ($payment['id'] ?? null) : null;
        $paymentStatus   = is_array($payment) ? ($payment['status'] ?? null) : null;

        // Normalize to your system statuses
        $status = match ($paymentStatus) {
            'COMPLETED' => 'paid',
            'FAILED', 'CANCELED' => 'failed',
            default => 'pending',
        };

        return [
            'provider'     => 'square',
            'event_id'     => $eventId,
            'event_type'   => $type,
            'order_id'     => null, // unless you map it yourself
            'provider_ref' => $squareOrderId,
            'status'       => $status,
            'payload'      => $event,
            'meta' => [
                'square_payment_id' => $squarePaymentId,
                'square_order_id'   => $squareOrderId,
                'square_status'     => $paymentStatus,
            ],
        ];
    }

    private function toCents($amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        return (int) str_replace('.', '', $normalized);
    }
}