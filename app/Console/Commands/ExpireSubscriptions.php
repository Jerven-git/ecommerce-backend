<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Support\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flip subscriptions whose paid period and grace window have both elapsed to expired';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionService $service): int
    {
        $graceDays = (int) config('subscriptions.grace_period_days', 3);
        $cutoff = now()->subDays($graceDays);

        $expired = 0;

        Store::query()
            ->whereIn('subscription_status', [
                Store::SUBSCRIPTION_ACTIVE,
                Store::SUBSCRIPTION_CANCELLED,
            ])
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<', $cutoff)
            ->each(function (Store $store) use ($service, &$expired): void {
                $service->markExpired($store);
                $expired++;
            });

        $this->info("Expired {$expired} subscription(s).");

        return self::SUCCESS;
    }
}
