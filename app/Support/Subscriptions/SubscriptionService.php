<?php

namespace App\Support\Subscriptions;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;

/**
 * State transitions for a store's platform subscription. The Store row carries
 * the single source of truth for gating (`subscription_status` +
 * `subscription_expires_at`); the `subscriptions` table is its audit ledger.
 */
class SubscriptionService
{
    /**
     * Activate a brand-new subscription (first successful payment).
     *
     * @param  array<string, mixed>  $providerData  Stripe refs + any extra keys stored in the ledger row's meta.
     */
    public function activate(Store $store, SubscriptionPlan $plan, array $providerData = []): Subscription
    {
        $startsAt = now();
        $endsAt = $this->periodEnd($plan, $startsAt);

        $store->update([
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'subscription_plan_id' => $plan->id,
            'subscribed_at' => $startsAt,
            'subscription_expires_at' => $endsAt,
        ]);

        return $store->subscriptions()->create([
            'subscription_plan_id' => $plan->id,
            'provider' => $providerData['provider'] ?? 'stripe',
            'provider_customer_id' => $providerData['provider_customer_id'] ?? null,
            'provider_subscription_id' => $providerData['provider_subscription_id'] ?? null,
            'provider_checkout_session_id' => $providerData['provider_checkout_session_id'] ?? null,
            'status' => Subscription::STATUS_ACTIVE,
            'mode' => Subscription::MODE_INITIAL,
            'period_starts_at' => $startsAt,
            'period_ends_at' => $endsAt,
            'meta' => $providerData['meta'] ?? null,
        ]);
    }

    /**
     * Extend an active subscription to a gateway-reported period end (renewals
     * and the invoice.paid webhook). Never shrinks the current window; skips
     * and returns null when the event is a no-op (e.g. the first invoice.paid
     * that mirrors an already-recorded activation).
     *
     * @param  array<string, mixed>  $providerData
     */
    public function extend(Store $store, SubscriptionPlan $plan, Carbon $endsAt, array $providerData = []): ?Subscription
    {
        if ($store->subscription_expires_at !== null && $store->subscription_expires_at->greaterThanOrEqualTo($endsAt)) {
            return null;
        }

        $store->update([
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'subscription_plan_id' => $plan->id,
            'subscription_expires_at' => $endsAt,
        ]);

        return $store->subscriptions()->create([
            'subscription_plan_id' => $plan->id,
            'provider' => $providerData['provider'] ?? 'stripe',
            'provider_customer_id' => $providerData['provider_customer_id'] ?? null,
            'provider_subscription_id' => $providerData['provider_subscription_id'] ?? null,
            'status' => Subscription::STATUS_ACTIVE,
            'mode' => Subscription::MODE_RENEWAL,
            'period_starts_at' => now(),
            'period_ends_at' => $endsAt,
            'meta' => $providerData['meta'] ?? null,
        ]);
    }

    /**
     * The customer cancelled at period end — access continues until the paid
     * period lapses, then the command/webhook flips it to expired.
     */
    public function markCancelled(Store $store, ?string $providerSubscriptionId = null): void
    {
        $store->update([
            'subscription_status' => Store::SUBSCRIPTION_CANCELLED,
        ]);

        if ($providerSubscriptionId !== null) {
            $store->subscriptions()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
                ->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);
        }
    }

    /**
     * Access is gone: mark the store expired. If the store is beyond its grace
     * window this hard-revokes until they resubscribe.
     */
    public function markExpired(Store $store): void
    {
        $store->update([
            'subscription_status' => Store::SUBSCRIPTION_EXPIRED,
        ]);

        $store->subscriptions()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
            ->update(['status' => Subscription::STATUS_EXPIRED]);
    }

    public function periodEnd(SubscriptionPlan $plan, ?Carbon $from = null): Carbon
    {
        $from ??= now();

        return match ($plan->interval) {
            'weekly' => $from->copy()->addWeek(),
            'quarterly' => $from->copy()->addMonths(3),
            'yearly' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }

    /**
     * Super Admin grants platform access (a comp). Never lapses; idempotent —
     * re-comping a comped store changes nothing and writes no ledger row.
     */
    public function comp(Store $store, ?SubscriptionPlan $plan = null): void
    {
        if ($store->subscription_status === Store::SUBSCRIPTION_COMPED) {
            if ($plan !== null && $store->subscription_plan_id !== $plan->id) {
                $store->update(['subscription_plan_id' => $plan->id]);
            }

            return;
        }

        $store->update([
            'subscription_status' => Store::SUBSCRIPTION_COMPED,
            'subscription_plan_id' => $plan?->id,
            'subscribed_at' => now(),
            'subscription_expires_at' => null,
        ]);

        $store->subscriptions()->create([
            'subscription_plan_id' => $plan?->id,
            'status' => Subscription::STATUS_ACTIVE,
            'mode' => Subscription::MODE_COMP,
            'period_starts_at' => now(),
        ]);
    }

    /**
     * Super Admin revokes a comp, returning the store to the pre-subscription
     * (gated) state. No-op for stores that aren't comped.
     */
    public function uncomp(Store $store): void
    {
        if ($store->subscription_status !== Store::SUBSCRIPTION_COMPED) {
            return;
        }

        $store->update([
            'subscription_status' => Store::SUBSCRIPTION_UNSUBSCRIBED,
            'subscription_plan_id' => null,
            'subscribed_at' => null,
            'subscription_expires_at' => null,
        ]);

        $store->subscriptions()
            ->where('mode', Subscription::MODE_COMP)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
            ->update([
                'status' => Subscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
    }
}
