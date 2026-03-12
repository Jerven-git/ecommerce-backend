<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Models\Order;

class UpdateOrderStatus
{
    public function handle(PaymentConfirmed $event): void
    {
        $order = Order::query()
            ->whereKey($event->payment->order_id)
            ->lockForUpdate()
            ->first();

        if (!$order) {
            return;
        }

        if ($order->status === 'pending') {
            $order->forceFill(['status' => 'processing'])->save();
        }
    }
}
