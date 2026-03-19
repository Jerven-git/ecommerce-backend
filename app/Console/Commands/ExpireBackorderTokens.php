<?php

namespace App\Console\Commands;

use App\Models\Backorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireBackorderTokens extends Command
{
    protected $signature = 'backorders:expire-tokens';

    protected $description = 'Expire backorder payment links that have passed their expiry time';

    public function handle(): int
    {
        $expired = Backorder::query()
            ->where('status', 'notified')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'payment_token' => null,
            ]);

        if ($expired > 0) {
            $message = "Expired {$expired} backorder payment links.";
            $this->info($message);
            Log::info($message);
        } else {
            $this->info('No expired backorder tokens found.');
        }

        return self::SUCCESS;
    }
}
