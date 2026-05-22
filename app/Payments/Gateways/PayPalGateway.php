<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\Contracts\HandlesWebhooks;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\PaymentCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PayPalGateway implements HandlesWebhooks, PaymentGateway
{
    public function __construct(private PaymentCredentials $credentials) {}

    public function key(): string
    {
        return 'paypal';
    }

    private function baseUrl(): string
    {
        return $this->credentials->get('paypal', 'mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function client(?string $token = null): PendingRequest
    {
        $req = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(15);

        if ($token) {
            $req = $req->withToken($token);
        }

        return $req;
    }

    private function withRetry(PendingRequest $req): PendingRequest
    {
        return $req->retry(
            2,
            200,
            function (\Throwable $e, ?Response $response) {
                if ($response === null) {
                    return true;
                } // network error
                $s = $response->status();

                return $s === 429 || $s >= 500;
            }
        );
    }

    /**
     * Prefer reusing your existing PayPalToken service (already used in WebhookController).
     * That service should cache tokens (recommended).
     */
    private function token(): string
    {
        return app(\App\Payments\PayPalToken::class)->get();
    }

    public function createPayment(Order $order, array $meta = []): array
    {
        $token = $this->token();

        $baseReturn = rtrim((string) config('payment.paypal.return_base_url'), '/');
        if ($baseReturn === '') {
            // fallback to app url if not configured
            $baseReturn = rtrim((string) config('app.url'), '/');
        }

        $currency = strtoupper($order->currency ?: 'USD');
        $rawAmount = $meta['amount_override'] ?? $order->total_amount;
        $value = number_format((float) $rawAmount, 2, '.', '');

        // must be unique per transaction
        $invoiceId = 'ORDER-'.$order->id.'-'.Str::uuid()->toString();

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => (string) $order->id,  // internal order id
                'invoice_id' => $invoiceId,           // unique
                'amount' => [
                    'currency_code' => $currency,
                    'value' => $value,
                ],
            ]],
            'application_context' => [
                'return_url' => $baseReturn.'/payment/complete',
                'cancel_url' => $baseReturn.'/payment/cancelled',
            ],
        ];

        // PayPal idempotency key
        $requestId = (string) Str::uuid();

        /** @var Response $res */
        $res = $this->withRetry(
            $this->client($token)->withHeaders([
                'PayPal-Request-Id' => $requestId,
            ])
        )->post($this->baseUrl().'/v2/checkout/orders', $payload);

        $res->throw();

        $data = $res->json();

        $paypalOrderId = data_get($data, 'id');
        $approvalUrl = collect($data['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        if (! $paypalOrderId || ! $approvalUrl) {
            throw new \RuntimeException('PayPal response missing expected fields.');
        }

        return [
            'provider' => 'paypal',
            'type' => 'redirect',
            'provider_ref' => $paypalOrderId,  // PayPal ORDER id (keep consistent)
            'approval_url' => $approvalUrl,
            'redirect_url' => $approvalUrl,
            'invoice_id' => $invoiceId,
            'paypal_request_id' => $requestId,
        ];
    }

    public function parseWebhook(array $event): array
    {
        $type = $event['event_type'] ?? null;
        $resource = $event['resource'] ?? [];

        // For PAYMENT.CAPTURE.* events, PayPal ORDER id is usually here:
        $paypalOrderId = data_get($resource, 'supplementary_data.related_ids.order_id');

        // Your internal order id (string)
        $orderId = $resource['custom_id'] ?? null;

        // Capture id (useful for audit/refunds)
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
            // MUST match what you store during createPayment():
            'provider_ref' => $paypalOrderId, // keep it PayPal ORDER id only
            'status' => $status,
            'payload' => $event,
            'meta' => array_filter([
                'capture_id' => $captureId,
                // keep invoice id if present (sometimes handy)
                'invoice_id' => $resource['invoice_id'] ?? null,
            ]),
        ];
    }
}
