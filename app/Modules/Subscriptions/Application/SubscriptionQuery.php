<?php

namespace App\Modules\Subscriptions\Application;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Collection;

class SubscriptionQuery
{
    /** @return Collection<int, array<string, mixed>> */
    public function plans(): Collection
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'interval' => $plan->interval,
                'price_cents' => $plan->price_cents,
                'setup_fee_cents' => $plan->setup_fee_cents,
                'features' => $plan->features,
            ]);
    }

    /** @return array<string, mixed>|null */
    public function status(User $user): ?array
    {
        $store = $this->activeStore($user);

        return $store ? $this->present($store) : null;
    }

    /** @return array<string, mixed> */
    public function present(Store $store): array
    {
        $store->load('subscriptionPlan');

        return [
            'subscription_status' => $store->subscription_status,
            'subscribed_at' => $store->subscribed_at,
            'subscription_expires_at' => $store->subscription_expires_at,
            'active' => $store->hasActiveSubscription(),
            'grace_period_days' => (int) config('subscriptions.grace_period_days'),
            'provider_customer_id' => $this->customerId($store),
            'plan' => $store->subscriptionPlan
                ? [
                    'name' => $store->subscriptionPlan->name,
                    'slug' => $store->subscriptionPlan->slug,
                    'price_cents' => $store->subscriptionPlan->price_cents,
                    'setup_fee_cents' => $store->subscriptionPlan->setup_fee_cents,
                    'interval' => $store->subscriptionPlan->interval,
                ]
                : null,
        ];
    }

    public function activeStore(User $user): ?Store
    {
        $store = $user->store;

        return $store && $store->isActive() ? $store : null;
    }

    public function customerId(Store $store): ?string
    {
        $id = null;

        if ($store->relationLoaded('subscriptions')) {
            $latest = $store->subscriptions->sortByDesc('id')->first();
            $id = $latest?->provider_customer_id;
        }

        if (! $id) {
            $id = $store->subscriptions()
                ->whereNotNull('provider_customer_id')
                ->latest('id')
                ->value('provider_customer_id');
        }

        return $id ?: null;
    }
}
