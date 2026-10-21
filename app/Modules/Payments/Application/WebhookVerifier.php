<?php

namespace App\Modules\Payments\Application;

use App\Payments\PaymentCredentials;
use App\Payments\PayPalToken;
use App\Payments\VerifySquareSignature;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WebhookVerifier
{
    public function __construct(
        private readonly PaymentCredentials $credentials,
        private readonly PayPalToken $paypalToken,
    ) {}

    public function verify(string $provider, Request $request): WebhookVerification
    {
        return match ($provider) {
            'stripe' => $this->verifyStripe($request),
            'square' => $this->verifySquare($request),
            'paypal' => $this->verifyPayPal($request),
            default => WebhookVerification::stopped('Unsupported provider', 400),
        };
    }

    private function verifyStripe(Request $request): WebhookVerification
    {
        $secret = $this->credentials->get('stripe', 'webhook_secret');

        if (! $secret) {
            return WebhookVerification::stopped('Stripe webhook not configured', 200);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                $secret,
            );

            return WebhookVerification::accepted($event->toArray());
        } catch (\Throwable) {
            return WebhookVerification::stopped('Invalid signature', 400);
        }
    }

    private function verifySquare(Request $request): WebhookVerification
    {
        $valid = VerifySquareSignature::isValid(
            $request->url(),
            (string) $this->credentials->get('square', 'webhook_secret'),
            $request->getContent(),
            $request->header('x-square-hmacsha256-signature'),
        );

        if (! $valid) {
            return WebhookVerification::stopped('Invalid signature', 403);
        }

        $event = json_decode($request->getContent(), true);

        return WebhookVerification::accepted(is_array($event) ? $event : []);
    }

    private function verifyPayPal(Request $request): WebhookVerification
    {
        try {
            $event = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return WebhookVerification::stopped('Invalid JSON', 400);
        }

        $webhookId = $this->credentials->get('paypal', 'webhook_id');
        if (! $webhookId) {
            return WebhookVerification::stopped('PayPal webhook_id not configured', 200);
        }

        $certUrl = (string) $request->header('paypal-cert-url');
        $incomingLooksSandbox = str_contains($certUrl, 'sandbox');
        $configuredIsSandbox = $this->credentials->get('paypal', 'mode') !== 'live';

        if ($configuredIsSandbox !== $incomingLooksSandbox) {
            return WebhookVerification::stopped('OK', 200);
        }

        $baseUrl = $configuredIsSandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        /** @var Response $response */
        $response = Http::withToken($this->paypalToken->get())
            ->connectTimeout(3)
            ->timeout(8)
            ->retry(2, 200, function ($exception, $response) {
                if ($response === null) {
                    return true;
                }

                return $response->status() === 429 || $response->status() >= 500;
            })
            ->post($baseUrl.'/v1/notifications/verify-webhook-signature', [
                'transmission_id' => $request->header('paypal-transmission-id'),
                'transmission_time' => $request->header('paypal-transmission-time'),
                'cert_url' => $certUrl,
                'auth_algo' => $request->header('paypal-auth-algo'),
                'transmission_sig' => $request->header('paypal-transmission-sig'),
                'webhook_id' => $webhookId,
                'webhook_event' => $event,
            ]);

        if (! $response->ok() || $response->json('verification_status') !== 'SUCCESS') {
            return WebhookVerification::stopped('OK', 200);
        }

        $request->attributes->set('paypal_verified', true);

        return WebhookVerification::accepted($event);
    }
}
