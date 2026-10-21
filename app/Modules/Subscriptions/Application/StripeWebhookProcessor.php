<?php

namespace App\Modules\Subscriptions\Application;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionWebhookEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class StripeWebhookProcessor
{
    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    /** @param array<string, mixed> $event */
    public function process(array $event): void
    {
        $eventId = $event['id'] ?? null;
        if (! $eventId) {
            throw new SubscriptionOperationException('Missing event id', 400);
        }

        $stored = SubscriptionWebhookEvent::firstOrCreate(
            ['provider' => 'stripe', 'event_id' => $eventId],
            [
                'event_type' => $event['type'] ?? null,
                'payload' => $event,
            ],
        );

        if (! $stored->wasRecentlyCreated) {
            return;
        }

        try {
            $this->apply($event);
        } catch (\Throwable $exception) {
            Log::channel('single')->error('subscription webhook processing failed', [
                'event_id' => $eventId,
                'event_type' => $event['type'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $event */
    private function apply(array $event): void
    {
        $type = $event['type'] ?? null;
        $object = $event['data']['object'] ?? [];

        match ($type) {
            'checkout.session.completed' => $this->checkoutCompleted($object),
            'invoice.paid' => $this->invoicePaid($object),
            'invoice.payment_failed' => $this->invoicePaymentFailed($object),
            'customer.subscription.deleted',
            'customer.subscription.updated' => $this->subscriptionStatusChanged($object),
            default => null,
        };
    }

    /** @param array<string, mixed> $session */
    private function checkoutCompleted(array $session): void
    {
        $store = Store::query()->find((int) ($session['client_reference_id'] ?? 0));
        if ($store === null) {
            return;
        }

        $plan = $store->subscriptionPlan
            ?? SubscriptionPlan::query()
                ->where('slug', (string) ($session['metadata']['plan_slug'] ?? ''))
                ->orWhere('is_active', true)
                ->orderBy('price_cents')
                ->first();

        if ($plan === null) {
            return;
        }

        $this->lifecycle->activate($store, $plan, [
            'provider' => 'stripe',
            'provider_customer_id' => $session['customer'] ?? null,
            'provider_subscription_id' => $session['subscription'] ?? null,
            'provider_checkout_session_id' => $session['id'] ?? null,
            'meta' => ['payment_status' => $session['payment_status'] ?? null],
        ]);
    }

    /** @param array<string, mixed> $invoice */
    private function invoicePaid(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;
        if (! $subscriptionId) {
            return;
        }

        $store = $this->storeBySubscription($subscriptionId, $invoice['customer'] ?? null);
        $periodEnd = $this->periodEndSeconds($invoice);
        if ($store === null || $store->subscriptionPlan === null || $periodEnd === null) {
            return;
        }

        $this->lifecycle->extend(
            $store,
            $store->subscriptionPlan,
            Carbon::createFromTimestampUTC($periodEnd),
            [
                'provider' => 'stripe',
                'provider_customer_id' => $invoice['customer'] ?? null,
                'provider_subscription_id' => $subscriptionId,
            ],
        );
    }

    /** @param array<string, mixed> $invoice */
    private function invoicePaymentFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;
        if (! $subscriptionId) {
            return;
        }

        $store = $this->storeBySubscription($subscriptionId, $invoice['customer'] ?? null);
        if ($store === null) {
            return;
        }

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

    /** @param array<string, mixed> $subscription */
    private function subscriptionStatusChanged(array $subscription): void
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

        if (in_array($status, ['canceled', 'unpaid'], true)) {
            if ($periodEnd !== null && $periodEnd->isPast()) {
                $this->lifecycle->markExpired($store);
            } else {
                $this->lifecycle->markCancelled($store, $subscriptionId);
            }

            return;
        }

        if ($status === 'past_due') {
            return;
        }

        if (in_array($status, ['incomplete_expired', 'incomplete'], true) || ($periodEnd !== null && $periodEnd->isPast())) {
            $this->lifecycle->markExpired($store);
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

    /** @param array<string, mixed> $invoice */
    private function periodEndSeconds(array $invoice): ?int
    {
        $ends = collect($invoice['lines']['data'] ?? [])
            ->map(fn ($line) => (int) ($line['period']['end'] ?? 0))
            ->filter(fn ($end) => $end > 0)
            ->max();

        return $ends > 0 ? $ends : null;
    }
}
