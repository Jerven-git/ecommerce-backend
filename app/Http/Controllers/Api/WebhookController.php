<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use App\Payments\PayPalToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(string $provider, Request $request, GatewayManager $manager, PaymentService $payments)
    {
        Log::info('Webhook hit', [
            'provider' => $provider,
            'path' => $request->path(),
        ]);

        $raw = $request->getContent();
        $eventArr = null;

        if ($provider === 'stripe') {
            if (!config('payment.stripe.webhook_secret')) {
                return response('Stripe webhook not configured', 200);
            }

            $sig = $request->header('Stripe-Signature');
            $secret = config('payment.stripe.webhook_secret');

            try {
                $event = \Stripe\Webhook::constructEvent($raw, $sig, $secret);
                $eventArr = $event->toArray();
            } catch (\Throwable $e) {
                return response('Invalid signature', 400);
            }
        }

        if ($provider === 'square') {
            $signature = $request->header('x-square-hmacsha256-signature');

            $ok = \App\Payments\VerifySquareSignature::isValid(
                config('payment.square.webhook_url'),
                config('payment.square.webhook_secret'),
                $raw,
                $signature
            );

            if (!$ok) return response('Invalid signature', 403);

            $eventArr = json_decode($raw, true) ?? [];
        }

        if ($provider === 'paypal') {
            try {
                $eventArr = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                return response('Invalid JSON', 400);
            }

            if (!config('payment.paypal.webhook_id')) {
                return response('PayPal webhook_id not configured', 200);
            }

            $eventId   = $eventArr['id'] ?? null;
            $eventType = $eventArr['event_type'] ?? null;

            if (!$eventId) {
                return response('Missing event id', 400);
            }

            $webhookEvent = \App\Models\PaymentWebhookEvent::firstOrCreate(
                ['provider' => 'paypal', 'event_id' => $eventId],
                ['event_type' => $eventType, 'payload' => $eventArr]
            );

            if (!$webhookEvent->wasRecentlyCreated) {
                return response('OK', 200);
            }

            $certUrl = (string) $request->header('paypal-cert-url');

            $incomingLooksSandbox = str_contains($certUrl, 'sandbox');
            $configuredIsSandbox  = config('payment.paypal.mode') !== 'live';

            // If you're configured sandbox but cert_url is LIVE -> mismatch -> don't process
            if ($configuredIsSandbox && !$incomingLooksSandbox) {
                Log::warning('PayPal env mismatch: configured sandbox but cert_url looks live', [
                    'event_id' => $eventId,
                    'cert_url' => $certUrl,
                    'mode' => config('payment.paypal.mode'),
                ]);

                return response('OK', 200);
            }

            // (Optional) If you're configured live but cert_url looks sandbox
            if (!$configuredIsSandbox && $incomingLooksSandbox) {
                Log::warning('PayPal env mismatch: configured live but cert_url looks sandbox', [
                    'event_id' => $eventId,
                    'cert_url' => $certUrl,
                    'mode' => config('payment.paypal.mode'),
                ]);

                return response('OK', 200);
            }

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
                Log::warning('PayPal verify endpoint error', [
                    'event_id' => $eventId,
                    'status' => $verifyRes->status(),
                    'body' => $verifyRes->body(),
                ]);

                return response('OK', 200);
            }

            if (($verifyRes->json('verification_status') ?? '') !== 'SUCCESS') {
                Log::warning('PayPal signature verification failed', [
                    'event_id' => $eventId,
                    'verification_status' => $verifyRes->json('verification_status'),
                ]);

                return response('OK', 200);
            }

            $request->attributes->set('paypal_verified', true);
        }

        if (!is_array($eventArr)) {
            return response('Unsupported provider or invalid payload', 400);
        }

        $gateway = $manager->get($provider);
        $normalized = $gateway->parseWebhook($eventArr);

        $eventId = $normalized['event_id'] ?? null;
        if (!$eventId) return response('Missing event id', 400);

        try {
            PaymentWebhookEvent::create([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $normalized['event_type'] ?? null,
                'payload' => $normalized['payload'] ?? $eventArr,
            ]);
        } catch (\Throwable $e) {
            return response('OK', 200);
        }

        $providerRef = $normalized['provider_ref'] ?? null;
        $orderId = $normalized['order_id'] ?? null;

        $payment = null;

        if ($providerRef) {
            $payment = Payment::where('provider', $provider)
                ->where('provider_ref', $providerRef)
                ->latest()
                ->first();
        }

        if (!$payment && $orderId) {
            $payment = Payment::where('order_id', $orderId)
                ->where('provider', $provider)
                ->latest()
                ->first();
        }

        if (!$payment) {
            // Acknowledge to stop retries; you can investigate via PaymentWebhookEvent table
            return response('OK', 200);
        }

        // store extra meta if provided
        if (!empty($normalized['meta'])) {
            $payment->update([
                'meta' => array_merge($payment->meta ?? [], $normalized['meta']),
            ]);
        }

        if (($normalized['status'] ?? null) === 'paid') {
            $payments->markPaid($payment);
        }

        return response('OK', 200);
    }
}