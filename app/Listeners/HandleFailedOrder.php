<?php

namespace App\Listeners;

use App\Events\OrderRequiresRefund;
use Illuminate\Support\Facades\Log;

class HandleFailedOrder
{
    public function handle(OrderRequiresRefund $event): void
    {
        Log::warning('Order requires refund', [
            'order_id' => $event->order->id,
            'reason'   => $event->reason,
        ]);

        // TODO: Trigger automated refund via payment gateway
        // TODO: Send notification to admin
    }
}
