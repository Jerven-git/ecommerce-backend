<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Payments\Contracts\HandlesWebhooks;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class WebhookController extends Controller
{
    public function handle(
        string $provider,
        Request $request,
        GatewayManager $manager,
        PaymentService $payments
    ) {
        $provider = strtolower(trim($provider));
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

        // 3) Dedupe early (all providers)
        $created = $this->storeWebhookEventOnce($provider, $normalized, $eventArr);
        if (!$created) {
            return response('OK', 200);
        }

        // 4) Resolve payment (by provider_ref then order_id)
        $payment = $this->resolvePaymentFromNormalized($provider, $normalized);
        if (!$payment) {
            return response('OK', 200);
        }

        // 5) Merge meta
        $this->mergePaymentMeta($payment, $normalized);

        // 6) Apply payment status
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
            // Prevent retry loop if misconfigured
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
            (string) config('payment.square.webhook_notification_url'),
            (string) config('payment.square.webhook_signature_key'),
            $raw,
            $signature
        );

        if (!$ok) {
            return [null, response('Invalid signature', 403)];
        }

        $decoded = json_decode($raw, true);
        return [is_array($decoded) ? $decoded : [], null];
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

        // Env mismatch guard
        $certUrl = (string) $request->header('paypal-cert-url');
        $incomingLooksSandbox = str_contains($certUrl, 'sandbox');
        $configuredIsSandbox  = config('payment.paypal.mode') !== 'live';

        if ($configuredIsSandbox !== $incomingLooksSandbox) {
            return [null, response('OK', 200)];
        }

        $base = $configuredIsSandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $token = app(\App\Payments\PayPalToken::class)->get();

        /** @var Response $verifyRes */
        $verifyRes = Http::withToken($token)
            ->connectTimeout(3)
            ->timeout(8)
            ->retry(2, 200, function ($e, $res) {
                // retry network + 429 + 5xx
                if ($res === null) return true;
                $s = $res->status();
                return $s === 429 || $s >= 500;
            })
            ->post($base . '/v1/notifications/verify-webhook-signature', [
                'transmission_id'   => $request->header('paypal-transmission-id'),
                'transmission_time' => $request->header('paypal-transmission-time'),
                'cert_url'          => $certUrl,
                'auth_algo'         => $request->header('paypal-auth-algo'),
                'transmission_sig'  => $request->header('paypal-transmission-sig'),
                'webhook_id'        => config('payment.paypal.webhook_id'),
                'webhook_event'     => $eventArr,
            ]);

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

        if (!$gateway instanceof HandlesWebhooks) {
            // This means someone hit /webhooks/{provider} for a provider that isn't webhook-capable
            throw new BadRequestHttpException("Provider does not support webhooks: {$provider}");
            // Or: return []; and handle as 400 above
        }

        return $gateway->parseWebhook($eventArr);
    }

    /**
     * Store once. Return true if inserted, false if duplicate.
     */
    private function storeWebhookEventOnce(string $provider, array $normalized, array $fallbackPayload): bool
    {
        $eventId = $normalized['event_id'] ?? null;
        if (!$eventId) return true; // let it continue; caller already checks

        // Requires a unique index on (provider, event_id)
        $event = PaymentWebhookEvent::firstOrCreate(
            ['provider' => $provider, 'event_id' => $eventId],
            [
                'event_type' => $normalized['event_type'] ?? null,
                'payload'    => $normalized['payload'] ?? $fallbackPayload,
            ]
        );

        return $event->wasRecentlyCreated;
    }

    private function resolvePaymentFromNormalized(string $provider, array $normalized): ?Payment
    {
        $providerRef = $normalized['provider_ref'] ?? null;
        $orderId     = $normalized['order_id'] ?? null;

        if ($provider === 'square') {
            $squarePaymentId = $normalized['meta']['square_payment_id'] ?? null;

            if ($squarePaymentId) {
                $payment = Payment::query()
                    ->where('provider', 'square')
                    ->where('meta->square_payment_id', $squarePaymentId)
                    ->latest()
                    ->first();

                if ($payment) return $payment;
            }
        }

        if ($providerRef) {
            $payment = Payment::query()
                ->where('provider', $provider)
                ->where('provider_ref', $providerRef)
                ->latest()
                ->first();

            if ($payment) return $payment;
        }

        if ($orderId) {
            return Payment::query()
                ->where('order_id', (int) $orderId)
                ->where('provider', $provider)
                ->latest()
                ->first();
        }

        return null;
    }

    private function mergePaymentMeta(Payment $payment, array $normalized): void
    {
        $meta = $normalized['meta'] ?? null;
        if (!is_array($meta) || empty($meta)) return;

        // Light concurrency-safe approach: refresh before merge
        $payment->refresh();

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
