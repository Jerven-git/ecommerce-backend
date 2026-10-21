<?php

namespace App\Modules\Subscriptions\Application;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Subscriptions\Stripe;

class SubscriptionBilling
{
    public function __construct(
        private readonly Stripe $stripe,
        private readonly SubscriptionQuery $subscriptions,
    ) {}

    public function checkout(User $user, ?string $planSlug): string
    {
        $store = $this->storeOrFail($user);
        $plan = $planSlug !== null
            ? SubscriptionPlan::query()->where('slug', $planSlug)->where('is_active', true)->firstOrFail()
            : SubscriptionPlan::query()->where('is_active', true)->orderBy('price_cents')->first();

        if ($plan === null) {
            throw new SubscriptionOperationException('No active subscription plans are available yet.');
        }

        $customerId = $this->subscriptions->customerId($store);
        if (! $customerId) {
            $customerId = $this->stripe->createCustomer((string) $user->email, $user->name);
            $this->memoizeCustomer($store, $customerId);
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return $this->stripe->createCheckoutSession([
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $store->id,
            'line_items' => $this->lineItems($plan),
            'metadata' => [
                'store_id' => (string) $store->id,
                'plan_slug' => $plan->slug,
            ],
            'success_url' => $frontend.'/admin/subscription?status=success',
            'cancel_url' => $frontend.'/admin/subscription?status=cancelled',
        ]);
    }

    public function portal(User $user): string
    {
        $store = $this->storeOrFail($user);

        if (! $store->hasActiveSubscription()) {
            throw new SubscriptionOperationException(
                'An active subscription is required to open the billing portal.',
                402,
                [
                    'code' => 'subscription_required',
                    'subscription_status' => $store->subscription_status,
                ],
            );
        }

        $customerId = $this->subscriptions->customerId($store);
        if (! $customerId) {
            throw new SubscriptionOperationException('No payment profile found for this store.');
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return $this->stripe->createBillingPortalSession(
            $customerId,
            $frontend.'/admin/subscription?status=portal',
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function lineItems(SubscriptionPlan $plan): array
    {
        $items = [];

        if ($plan->setup_fee_cents > 0) {
            $items[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $plan->name.' setup fee'],
                    'unit_amount' => (int) $plan->setup_fee_cents,
                ],
                'quantity' => 1,
            ];
        }

        $recurring = match ($plan->interval) {
            'weekly' => ['interval' => 'week'],
            'quarterly' => ['interval' => 'month', 'interval_count' => 3],
            'yearly' => ['interval' => 'year'],
            default => ['interval' => 'month'],
        };

        $items[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => $plan->name],
                'unit_amount' => (int) $plan->price_cents,
                'recurring' => $recurring,
            ],
            'quantity' => 1,
        ];

        return $items;
    }

    private function storeOrFail(User $user): Store
    {
        return $this->subscriptions->activeStore($user)
            ?? throw new SubscriptionOperationException('No store on this account.');
    }

    private function memoizeCustomer(Store $store, string $customerId): void
    {
        $store->subscriptions()->create([
            'status' => Subscription::STATUS_PENDING,
            'mode' => Subscription::MODE_INITIAL,
            'provider' => 'stripe',
            'provider_customer_id' => $customerId,
        ]);
    }
}
