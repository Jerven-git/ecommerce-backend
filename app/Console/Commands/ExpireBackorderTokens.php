<?php

namespace App\Console\Commands;

use App\Modules\Backorders\Application\BackorderLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireBackorderTokens extends Command
{
    protected $signature = 'backorders:expire-tokens';

    protected $description = 'Expire backorder payment links that have passed their expiry time';

    public function handle(BackorderLifecycle $backorders): int
    {
        $count = $backorders->expireTokens();
        if ($count === 0) {
            $this->info('No expired backorder tokens found.');

            return self::SUCCESS;
        }

        $message = "Expired {$count} backorder payment links.";
        $this->info($message);
        Log::info($message);

        return self::SUCCESS;
    }
}
