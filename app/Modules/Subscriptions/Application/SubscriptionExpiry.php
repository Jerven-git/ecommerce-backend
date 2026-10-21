<?php

namespace App\Modules\Subscriptions\Application;

use App\Models\Store;

class SubscriptionExpiry
{
    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    public function expire(): int
    {
        $cutoff = now()->subDays((int) config('subscriptions.grace_period_days', 3));
        $expired = 0;

        Store::query()
            ->whereIn('subscription_status', [
                Store::SUBSCRIPTION_ACTIVE,
                Store::SUBSCRIPTION_CANCELLED,
            ])
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<', $cutoff)
            ->each(function (Store $store) use (&$expired): void {
                $this->lifecycle->markExpired($store);
                $expired++;
            });

        return $expired;
    }
}
