<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Models\Backorder;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class FulfillBackorder
{
    public function handle(PaymentConfirmed $event): void
    {
        $payment = $event->payment;
        $meta = $payment->meta ?? [];
        $backorderId = $meta['backorder_id'] ?? null;

        if (!$backorderId) {
            return;
        }

        DB::transaction(function () use ($backorderId) {
            $backorder = Backorder::query()
                ->whereKey($backorderId)
                ->lockForUpdate()
                ->first();

            if (!$backorder || $backorder->status === 'paid') {
                return;
            }

            // Mark backorder as paid
            $backorder->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_token' => null,
                'token_expires_at' => null,
            ]);

            // Deduct stock for the backorder quantity
            $product = Product::query()
                ->whereKey($backorder->product_id)
                ->lockForUpdate()
                ->first();

            if ($product && $product->stock >= $backorder->quantity) {
                $product->decrement('stock', $backorder->quantity);
            }

            // If all backorders on the parent order are now paid/cancelled, update order status
            $order = $backorder->order;
            if ($order) {
                $activeBackorders = $order->backorders()
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->count();

                if ($activeBackorders === 0) {
                    $order->forceFill(['status' => 'processing'])->save();
                }
            }
        });
    }
}
