<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WebhookController extends Controller
{
    public function handle(string $provider, Request $request, GatewayManager $manager, PaymentService $payments)
    {
        $raw = $request->getContent();

        // 1) Parse + verify provider payload -> array
        [$eventArr, $errorResponse] = $this->parseAndVerifyProviderPayload($provider, $request, $raw);
        if ($errorResponse) return $errorResponse;

        if (!is_array($eventArr)) {
            return response('Unsupported provider or invalid payload', 400);
        }

        // 2) Normalize via gateway
        $normalized = $this->normalizeEvent($provider, $manager, $eventArr);

        $eventId = $normalized['event_id'] ?? null;
        if (!$eventId) return response('Missing event id', 400);

        // 3) Store event (dedupe via unique constraint; ignore duplicates)
        $this->storeWebhookEventOrIgnoreDuplicate($provider, $normalized, $eventArr);

        // 4) Resolve payment (by provider_ref then order_id)
        $payment = $this->resolvePaymentFromNormalized($provider, $normalized);

        if (!$payment) {
            return response('OK', 200);
        }

        // 5) Merge meta
        $this->mergePaymentMeta($payment, $normalized);

        // 6) Mark paid if needed
        $this->applyPaymentStatus($payment, $normalized, $payments);

        return response('OK', 200);
    }

    /**
     * @return array{0: ?array, 1: \Illuminate\Http\Response|\Illuminate\Http\JsonResponse|null}
     */
    private function parseAndVerifyProviderPayload(string $provider, Request $request, string $raw): array
    {
        return match ($provider) {
            'stripe' => $this->parseStripe($request, $raw),
            'square' => $this->parseSquare($request, $raw),
            'paypal' => $this->parsePayPalAndVerify($request, $raw),
            default  => [null, response('Unsupported provider', 400)],
        };
    }

    /**
     * @return array{0: ?array, 1: \Illuminate\Http\Response|null}
     */
    private function parseStripe(Request $request, string $raw): array
    {
        $secret = config('payment.stripe.webhook_secret');
        if (!$secret) {
            // Returning 200 prevents Stripe from retrying forever if you forgot to configure it
            return [null, response('Stripe webhook not configured', 200)];
        }

        $sig = $request->header('Stripe-Signature');

        try {
            $event = \Stripe\Webhook::constructEvent($raw, $sig, $secret);
            return [$event->toArray(), null];
        } catch (\Throwable) {
            return [null, response('Invalid signature', 400)];
        }
    }

    /**
     * @return array{0: ?array, 1: \Illuminate\Http\Response|null}
     */
    private function parseSquare(Request $request, string $raw): array
    {
        $signature = $request->header('x-square-hmacsha256-signature');

        $ok = \App\Payments\VerifySquareSignature::isValid(
            config('payment.square.webhook_url'),
            config('payment.square.webhook_secret'),
            $raw,
            $signature
        );

        if (!$ok) {
            return [null, response('Invalid signature', 403)];
        }

        return [json_decode($raw, true) ?? [], null];
    }

    /**
     * @return array{0: ?array, 1: \Illuminate\Http\Response|null}
     */
    private function parsePayPalAndVerify(Request $request, string $raw): array
    {
        try {
            $eventArr = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [null, response('Invalid JSON', 400)];
        }

        if (!config('payment.paypal.webhook_id')) {
            return [null, response('PayPal webhook_id not configured', 200)];
        }

        $eventId   = $eventArr['id'] ?? null;
        $eventType = $eventArr['event_type'] ?? null;

        if (!$eventId) {
            return [null, response('Missing event id', 400)];
        }

        // Dedupe early for PayPal
        $webhookEvent = PaymentWebhookEvent::firstOrCreate(
            ['provider' => 'paypal', 'event_id' => $eventId],
            ['event_type' => $eventType, 'payload' => $eventArr]
        );

        if (!$webhookEvent->wasRecentlyCreated) {
            return [null, response('OK', 200)];
        }

        // Environment mismatch guard (prevents verifying with wrong mode)
        $certUrl = (string) $request->header('paypal-cert-url');

        $incomingLooksSandbox = str_contains($certUrl, 'sandbox');
        $configuredIsSandbox  = config('payment.paypal.mode') !== 'live';

        if ($configuredIsSandbox && !$incomingLooksSandbox) {
            return [null, response('OK', 200)];
        }

        if (!$configuredIsSandbox && $incomingLooksSandbox) {
            return [null, response('OK', 200)];
        }

        // Verify via PayPal endpoint
        $base = $configuredIsSandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $token = app(\App\Payments\PayPalToken::class)->get();

        $verifyRes = Http::withToken($token)
            ->connectTimeout(3)
            ->timeout(8)
            ->retry(2, 200)
            ->post($base . '/v1/notifications/verify-webhook-signature', [
                'transmission_id'   => $request->header('paypal-transmission-id'),
                'transmission_time' => $request->header('paypal-transmission-time'),
                'cert_url'          => $certUrl,
                'auth_algo'         => $request->header('paypal-auth-algo'),
                'transmission_sig'  => $request->header('paypal-transmission-sig'),
                'webhook_id'        => config('payment.paypal.webhook_id'),
                'webhook_event'     => $eventArr,
            ]);

        /** @var Response $verifyRes */
        if (!$verifyRes->ok()) {
            return [null, response('OK', 200)];
        }

        if (($verifyRes->json('verification_status') ?? '') !== 'SUCCESS') {
            return [null, response('OK', 200)];
        }

        $request->attributes->set('paypal_verified', true);

        return [$eventArr, null];
    }

    private function normalizeEvent(string $provider, GatewayManager $manager, array $eventArr): array
    {
        $gateway = $manager->get($provider);
        return $gateway->parseWebhook($eventArr);
    }

    private function storeWebhookEventOrIgnoreDuplicate(string $provider, array $normalized, array $fallbackPayload): void
    {
        $eventId = $normalized['event_id'] ?? null;
        if (!$eventId) return;

        try {
            PaymentWebhookEvent::create([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $normalized['event_type'] ?? null,
                'payload' => $normalized['payload'] ?? $fallbackPayload,
            ]);
        } catch (\Throwable) {
            // ignore duplicates or insert failures (as per request: no logs)
        }
    }

    private function resolvePaymentFromNormalized(string $provider, array $normalized): ?Payment
    {
        $providerRef = $normalized['provider_ref'] ?? null;
        $orderId = $normalized['order_id'] ?? null;

        if ($providerRef) {
            $payment = Payment::where('provider', $provider)
                ->where('provider_ref', $providerRef)
                ->latest()
                ->first();

            if ($payment) return $payment;
        }

        if ($orderId) {
            return Payment::where('order_id', $orderId)
                ->where('provider', $provider)
                ->latest()
                ->first();
        }

        return null;
    }

    private function mergePaymentMeta(Payment $payment, array $normalized): void
    {
        $meta = $normalized['meta'] ?? null;
        if (empty($meta) || !is_array($meta)) return;

        $payment->update([
            'meta' => array_merge($payment->meta ?? [], $meta),
        ]);
    }

    private function applyPaymentStatus(Payment $payment, array $normalized, PaymentService $payments): void
    {
        if (!$payment->order_id && !empty($normalized['order_id'])) {
            $oid = (int) $normalized['order_id'];
            if ($oid > 0) {
                $payment->update(['order_id' => $oid]);
                $payment->order_id = $oid;
            }
        }

        if (($normalized['status'] ?? null) === 'paid') {
            $payments->markPaid($payment);
        }
    }
}
