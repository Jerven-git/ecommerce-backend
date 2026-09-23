<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionWebhookEvent;
use App\Support\Subscriptions\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionWebhookController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    /**
     * Handles platform-subscription Stripe events. Host-agnostic (providers hit
     * a fixed URL), signature-verified and deduplicated exactly once per event
     * id, mirroring the order-payment WebhookController.
     */
    public function handle(Request $request): Response
    {
        $secret = (string) config('payment.stripe.webhook_secret');

        if (! $secret) {
            // Absorb retries until a secret is configured, like the order path.
            return response('Stripe subscription webhook not configured', 200);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (\Throwable) {
            return response('Invalid signature', 400);
        }

        $eventArr = $event->toArray();
        $eventId = $eventArr['id'] ?? null;

        if (! $eventId) {
            return response('Missing event id', 400);
        }

        $stored = SubscriptionWebhookEvent::firstOrCreate(
            ['provider' => 'stripe', 'event_id' => $eventId],
            [
                'event_type' => $eventArr['type'] ?? null,
                'payload' => $eventArr,
            ]
        );

        if (! $stored->wasRecentlyCreated) {
            return response('OK', 200);
        }

        try {
            $this->process($eventArr);
        } catch (\Throwable $e) {
            // Don't 500 Stripe (it would retry forever); log and let the daily
            // expire job + manual reconciliation pick up the slack.
            Log::channel('single')->error('subscription webhook processing failed', [
                'event_id' => $eventId,
                'event_type' => $eventArr['type'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response('OK', 200);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function process(array $event): void
    {
        $type = $event['type'] ?? null;
        $object = $event['data']['object'] ?? [];

        match ($type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($object),
            'invoice.paid' => $this->onInvoicePaid($object),
            'invoice.payment_failed' => $this->onInvoicePaymentFailed($object),
            'customer.subscription.deleted' => $this->onSubscriptionStatusChanged($object),
            'customer.subscription.updated' => $this->onSubscriptionStatusChanged($object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function onCheckoutCompleted(array $session): void
    {
        $storeId = (int) ($session['client_reference_id'] ?? 0);
        $store = Store::query()->find($storeId);

        if ($store === null) {
            return;
        }

        $plan = $store->subscriptionPlan
            ?? \App\Models\SubscriptionPlan::query()
                ->where('slug', (string) ($session['metadata']['plan_slug'] ?? ''))
                ->orWhere('is_active', true)
                ->orderBy('price_cents')
                ->first();

        if ($plan === null) {
            return;
        }

        $this->subscriptions->activate($store, $plan, [
            'provider' => 'stripe',
            'provider_customer_id' => $session['customer'] ?? null,
            'provider_subscription_id' => $session['subscription'] ?? null,
            'provider_checkout_session_id' => $session['id'] ?? null,
            'meta' => [
                'payment_status' => $session['payment_status'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function onInvoicePaid(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $store = $this->storeBySubscription($subscriptionId, $invoice['customer'] ?? null);

        if ($store === null || $store->subscriptionPlan === null) {
            return;
        }

        $periodEnd = $this->periodEndSeconds($invoice);

        if ($periodEnd === null) {
            return;
        }

        $this->subscriptions->extend(
            $store,
            $store->subscriptionPlan,
            Carbon::createFromTimestampUTC($periodEnd),
            [
                'provider' => 'stripe',
                'provider_customer_id' => $invoice['customer'] ?? null,
                'provider_subscription_id' => $subscriptionId,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function onInvoicePaymentFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        // Access continues through the grace window: the store stays `active`
        // and the daily subscriptions:expire command flips it once the paid
        // period ends and the grace window elapses.
        $row = $store->subscriptions()
            ->where('provider_subscription_id', $subscriptionId)
            ->first();

        if ($row === null) {
            return;
        }

        $row->update([
            'meta' => array_merge($row->meta ?? [], [
                'payment_failed_at' => $invoice['created'] ?? null,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $subscription  Stripe subscription object.
     */
    private function onSubscriptionStatusChanged(array $subscription): void
    {
        $subscriptionId = $subscription['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $store = $this->storeBySubscription($subscriptionId, $subscription['customer'] ?? null);

        if ($store === null) {
            return;
        }

        $status = $subscription['status'] ?? null;
        $periodEnd = isset($subscription['current_period_end'])
            ? Carbon::createFromTimestampUTC((int) $subscription['current_period_end'])
            : null;

        // A subscription that merely stopped renewing keeps access until the
        // paid window lapses; one whose period has already passed (or was
        // never active) hard-revokes immediately.
        if (in_array($status, ['canceled', 'unpaid'], true)) {
            if ($periodEnd !== null && $periodEnd->isPast()) {
                $this->subscriptions->markExpired($store);
            } else {
                $this->subscriptions->markCancelled($store, $subscriptionId);
            }

            return;
        }

        if ($status === 'past_due') {
            // Within the grace window: leave it active; expiry is handled by
            // the daily command if no payment arrives.
            return;
        }

        if (in_array($status, ['incomplete_expired', 'incomplete'], true) || ($periodEnd !== null && $periodEnd->isPast())) {
            $this->subscriptions->markExpired($store);
        }
    }

    private function storeBySubscription(string $subscriptionId, mixed $customerId): ?Store
    {
        $store = Store::query()
            ->whereHas('subscriptions', fn ($query) => $query
                ->where('provider_subscription_id', $subscriptionId))
            ->first();

        if ($store !== null) {
            return $store;
        }

        if ($customerId) {
            return Store::query()
                ->whereHas('subscriptions', fn ($query) => $query
                    ->where('provider_customer_id', (string) $customerId))
                ->first();
        }

        return null;
    }

    /**
     * The invoice's paid period, i.e. the end of the newest line period.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function periodEndSeconds(array $invoice): ?int
    {
        $lines = $invoice['lines']['data'] ?? [];

        $ends = collect($lines)
            ->map(fn ($line) => (int) ($line['period']['end'] ?? 0))
            ->filter(fn ($end) => $end > 0)
            ->max();

        return $ends > 0 ? $ends : null;
    }
}
