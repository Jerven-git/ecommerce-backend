<?php

namespace App\Modules\ControlPlane\Application;

use App\Models\Store;

class StorePresenter
{
    public function present(Store $store): array
    {
        return [
            'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug,
            'domain' => $store->domain, 'domain_verified_at' => $store->domain_verified_at,
            'domain_verified' => $store->hasVerifiedDomain(), 'status' => $store->status,
            'timezone' => $store->timezone, 'resolved_timezone' => $store->resolvedTimezone(),
            'platform_timezone' => (string) config('app.timezone', 'UTC'),
            'subscription_status' => $store->subscription_status,
            'subscription_plan_id' => $store->subscription_plan_id,
            'subscription_plan' => $store->subscriptionPlan ? [
                'id' => $store->subscriptionPlan->id, 'name' => $store->subscriptionPlan->name,
                'slug' => $store->subscriptionPlan->slug, 'price_cents' => $store->subscriptionPlan->price_cents,
                'setup_fee_cents' => $store->subscriptionPlan->setup_fee_cents,
                'interval' => $store->subscriptionPlan->interval,
            ] : null,
            'subscribed_at' => $store->subscribed_at,
            'subscription_expires_at' => $store->subscription_expires_at,
            'is_default' => $store->slug === Store::DEFAULT_SLUG,
            'users_count' => $store->users_count ?? 0,
            'created_at' => $store->created_at, 'updated_at' => $store->updated_at,
            'deleted_at' => $store->deleted_at,
        ];
    }
}
