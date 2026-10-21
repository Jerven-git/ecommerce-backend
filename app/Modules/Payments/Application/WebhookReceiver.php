<?php

namespace App\Modules\Payments\Application;

use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Store;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Request;

class WebhookReceiver
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly GatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly CurrentStore $currentStore,
    ) {}

    public function receive(string $provider, Request $request, ?string $storeSlug): WebhookResult
    {
        $provider = strtolower(trim($provider));
        $this->setStoreContext($storeSlug);

        $verification = $this->verifier->verify($provider, $request);
        if ($verification->terminalResult) {
            return $verification->terminalResult;
        }

        $event = $verification->event;
        if (! is_array($event)) {
            return WebhookResult::reject('Unsupported provider or invalid payload');
        }

        $normalized = $this->gateways->webhook($provider)->parseWebhook($event);
        if (empty($normalized['event_id'])) {
            return WebhookResult::reject('Missing event id');
        }

        if (! $this->storeEventOnce($provider, $normalized, $event)) {
            return WebhookResult::ok();
        }

        $payment = $this->resolvePayment($provider, $normalized);
        if (! $payment) {
            return WebhookResult::ok();
        }

        $this->mergeMeta($payment, $normalized);
        $this->applyStatus($payment, $normalized);

        return WebhookResult::ok();
    }

    private function setStoreContext(?string $storeSlug): void
    {
        if ($storeSlug !== null && $storeSlug !== '') {
            $store = Store::query()->where('slug', $storeSlug)->first();
            abort_if(! $store || ! $store->isActive(), 404, 'Unknown store');
        } else {
            $store = Store::query()->where('slug', Store::DEFAULT_SLUG)->first();
        }

        if ($store) {
            $this->currentStore->set($store);
        }
    }

    /** @param array<string, mixed> $normalized @param array<string, mixed> $fallbackPayload */
    private function storeEventOnce(string $provider, array $normalized, array $fallbackPayload): bool
    {
        $event = PaymentWebhookEvent::firstOrCreate(
            ['provider' => $provider, 'event_id' => $normalized['event_id']],
            [
                'event_type' => $normalized['event_type'] ?? null,
                'payload' => $normalized['payload'] ?? $fallbackPayload,
            ],
        );

        return $event->wasRecentlyCreated;
    }

    /** @param array<string, mixed> $normalized */
    private function resolvePayment(string $provider, array $normalized): ?Payment
    {
        $providerRef = $normalized['provider_ref'] ?? null;
        $orderId = $normalized['order_id'] ?? null;
        $query = Payment::query()->whereHas('order', function ($query) {
            $query->where('store_id', $this->currentStore->id());
        });

        if ($provider === 'square' && ! empty($normalized['meta']['square_payment_id'])) {
            $payment = (clone $query)
                ->where('provider', 'square')
                ->where('meta->square_payment_id', $normalized['meta']['square_payment_id'])
                ->latest()
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if ($providerRef) {
            $payment = (clone $query)
                ->where('provider', $provider)
                ->where('provider_ref', $providerRef)
                ->latest()
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if ($orderId) {
            return $query
                ->where('order_id', (int) $orderId)
                ->where('provider', $provider)
                ->latest()
                ->first();
        }

        return null;
    }

    /** @param array<string, mixed> $normalized */
    private function mergeMeta(Payment $payment, array $normalized): void
    {
        $meta = $normalized['meta'] ?? null;
        if (! is_array($meta) || empty($meta)) {
            return;
        }

        $payment->refresh();
        $payment->update(['meta' => array_merge($payment->meta ?? [], $meta)]);
    }

    /** @param array<string, mixed> $normalized */
    private function applyStatus(Payment $payment, array $normalized): void
    {
        if (! $payment->order_id && ! empty($normalized['order_id'])) {
            $orderId = (int) $normalized['order_id'];
            if ($orderId > 0) {
                $payment->update(['order_id' => $orderId]);
                $payment->order_id = $orderId;
            }
        }

        $status = $normalized['status'] ?? null;
        if ($status === 'paid') {
            $this->payments->markPaid($payment);
        } elseif ($status === 'failed' && $payment->status === 'pending') {
            $payment->update(['status' => 'failed']);
        }
    }
}
