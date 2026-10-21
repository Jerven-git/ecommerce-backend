<?php

namespace App\Console\Commands;

use App\Modules\Subscriptions\Application\SubscriptionExpiry;
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
    public function handle(SubscriptionExpiry $subscriptions): int
    {
        $expired = $subscriptions->expire();

        $this->info("Expired {$expired} subscription(s).");

        return self::SUCCESS;
    }
}
