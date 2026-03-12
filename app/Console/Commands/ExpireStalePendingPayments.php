<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireStalePendingPayments extends Command
{
    protected $signature = 'payments:expire-stale {--minutes=30 : Minutes after which a pending payment is considered stale}';

    protected $description = 'Expire pending payments that have been inactive beyond the threshold';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $stalePayments = Payment::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($stalePayments->isEmpty()) {
            $this->info('No stale pending payments found.');
            return self::SUCCESS;
        }

        $expiredCount = 0;
        $cancelledOrders = 0;

        foreach ($stalePayments as $payment) {
            $payment->update(['status' => 'expired']);
            $expiredCount++;

            // Cancel the associated order if it's still pending
            if ($payment->order_id) {
                $affected = Order::query()
                    ->whereKey($payment->order_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);

                $cancelledOrders += $affected;
            }
        }

        $message = "Expired {$expiredCount} stale payments, cancelled {$cancelledOrders} orders.";
        $this->info($message);
        Log::info($message);

        return self::SUCCESS;
    }
}
