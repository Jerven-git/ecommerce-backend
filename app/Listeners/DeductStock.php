<?php

namespace App\Listeners;

use App\Events\OrderRequiresRefund;
use App\Events\PaymentConfirmed;
use App\Models\Order;
use App\Models\Product;
use App\Payments\InsufficientStockException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeductStock
{
    public function handle(PaymentConfirmed $event): void
    {
        $payment = $event->payment;

        if (!$payment->order_id) {
            return;
        }

        try {
            DB::transaction(function () use ($payment) {
                $order = Order::query()
                    ->whereKey($payment->order_id)
                    ->with('items')
                    ->lockForUpdate()
                    ->first();

                if (!$order || $order->stock_deducted_at) {
                    return;
                }

                $qtyByProduct = $order->items
                    ->groupBy('product_id')
                    ->map(fn ($rows) => (int) $rows->sum('quantity'));

                if ($qtyByProduct->isEmpty()) {
                    $order->forceFill(['stock_deducted_at' => now()])->save();
                    return;
                }

                // Lock products in ID order to prevent deadlocks
                $products = Product::query()
                    ->whereIn('id', $qtyByProduct->keys()->all())
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Separate backorder items from regular items
                $backorderProductIds = $order->backorders()
                    ->whereIn('status', ['awaiting_stock', 'notified', 'expired'])
                    ->pluck('product_id')
                    ->all();

                foreach ($qtyByProduct as $productId => $qty) {
                    // Skip backorder items — stock is deducted when backorder is paid
                    if (in_array($productId, $backorderProductIds)) {
                        continue;
                    }

                    $product = $products->get($productId);

                    if (!$product) {
                        throw new RuntimeException("Product not found: {$productId}");
                    }

                    if ($product->stock < $qty) {
                        throw new InsufficientStockException($product->id, $product->name, $product->stock, $qty);
                    }
                }

                $order->forceFill(['stock_deducted_at' => now()])->save();

                foreach ($qtyByProduct as $productId => $qty) {
                    if (in_array($productId, $backorderProductIds)) {
                        continue;
                    }
                    $products[$productId]->decrement('stock', $qty);
                }
            });
        } catch (InsufficientStockException|RuntimeException $e) {
            $order = Order::find($payment->order_id);

            if ($order) {
                $order->forceFill(['status' => 'failed_needs_refund'])->save();
                OrderRequiresRefund::dispatch($order, $e->getMessage());
            }

            report($e);
        }
    }
}
