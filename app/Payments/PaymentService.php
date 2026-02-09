<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
class PaymentService
{
    public function createPending(Order $order, string $provider, array $init): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'provider' => $provider,
            'provider_ref' => $init['provider_ref'] ?? null,
            'status' => 'pending',
            'amount' => (int) round($order->total_amount * 100),
            'currency' => strtoupper($order->currency ?? 'USD'),
            'meta' => $init,
        ]);
    }

    public function markPaid(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            // lock payment row to prevent concurrent webhook double-processing
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // idempotent: already paid
            if ($payment->status === 'paid') {
                return;
            }

            $payment->update(['status' => 'paid']);

            // If payment is not linked to an order, nothing to finalize
            if (!$payment->order_id) {
                return;
            }

            $order = Order::whereKey($payment->order_id)
                ->with('items')
                ->lockForUpdate()
                ->first();

            if (!$order) return;

            // idempotent: stock already deducted
            if ($order->stock_deducted_at) {
                if ($order->status === 'pending') {
                    $order->update(['status' => 'processing']);
                }
                return;
            }

            foreach ($order->items as $item) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();

                if ($product->stock < (int) $item->quantity) {
                    throw new \Exception("Insufficient stock for product: {$product->name}");
                }

                $product->decrement('stock', (int) $item->quantity);
            }

            $order->update([
                'stock_deducted_at' => now(),
                // optional: when paid, order moves to processing
                'status' => $order->status === 'pending' ? 'processing' : $order->status,
            ]);
        });
    }
}
