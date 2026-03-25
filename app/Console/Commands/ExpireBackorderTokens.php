<?php

namespace App\Console\Commands;

use App\Models\Backorder;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireBackorderTokens extends Command
{
    protected $signature = 'backorders:expire-tokens';

    protected $description = 'Expire backorder payment links that have passed their expiry time';

    public function handle(): int
    {
        $expiredBackorders = Backorder::query()
            ->where('status', 'notified')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now())
            ->get();

        if ($expiredBackorders->isEmpty()) {
            $this->info('No expired backorder tokens found.');
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($expiredBackorders as $backorder) {
            DB::transaction(function () use ($backorder) {
                // Release reserved stock back to the product
                if ($backorder->stock_reserved) {
                    $product = Product::where('id', $backorder->product_id)->lockForUpdate()->first();
                    if ($product) {
                        $product->increment('stock', $backorder->quantity);
                    }
                }

                $backorder->update([
                    'status' => 'expired',
                    'payment_token' => null,
                    'stock_reserved' => false,
                ]);
            });

            $count++;
        }

        $message = "Expired {$count} backorder payment links.";
        $this->info($message);
        Log::info($message);

        return self::SUCCESS;
    }
}
