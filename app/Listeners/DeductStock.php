<?php

namespace App\Listeners;

use App\Events\OrderRequiresRefund;
use App\Events\PaymentConfirmed;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Payments\InsufficientStockException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeductStock
{
    public function handle(PaymentConfirmed $event): void
    {
        $payment = $event->payment;

        if (! $payment->order_id) {
            return;
        }

        try {
            DB::transaction(function () use ($payment) {
                $order = Order::query()
                    ->whereKey($payment->order_id)
                    ->with('items')
                    ->lockForUpdate()
                    ->first();

                if (! $order || $order->stock_deducted_at) {
                    return;
                }

                if ($order->items->isEmpty()) {
                    $order->forceFill(['stock_deducted_at' => now()])->save();

                    return;
                }

                // Separate backorder items from regular items
                $backorderProductIds = $order->backorders()
                    ->whereIn('status', ['awaiting_stock', 'notified', 'expired'])
                    ->pluck('product_id')
                    ->all();

                $variantItems = $order->items->filter(
                    fn ($i) => $i->variant_id !== null && ! in_array($i->product_id, $backorderProductIds)
                );
                $productItems = $order->items->filter(
                    fn ($i) => $i->variant_id === null && ! in_array($i->product_id, $backorderProductIds)
                );

                // Lock variants in ID order to prevent deadlocks
                $qtyByVariant = $variantItems->groupBy('variant_id')
                    ->map(fn ($rows) => (int) $rows->sum('quantity'));

                $variants = collect();
                if ($qtyByVariant->isNotEmpty()) {
                    $variants = ProductVariant::query()
                        ->whereIn('id', $qtyByVariant->keys()->all())
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                }

                // Lock products in ID order to prevent deadlocks
                $qtyByProduct = $productItems->groupBy('product_id')
                    ->map(fn ($rows) => (int) $rows->sum('quantity'));

                $products = collect();
                if ($qtyByProduct->isNotEmpty()) {
                    $products = Product::query()
                        ->whereIn('id', $qtyByProduct->keys()->all())
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                }

                // Validate stock before decrementing anything
                foreach ($qtyByVariant as $variantId => $qty) {
                    $variant = $variants->get($variantId);
                    if (! $variant) {
                        throw new RuntimeException("Variant not found: {$variantId}");
                    }
                    if ($variant->stock < $qty) {
                        throw new InsufficientStockException($variant->product_id, "Variant #{$variantId}", $variant->stock, $qty);
                    }
                }

                foreach ($qtyByProduct as $productId => $qty) {
                    $product = $products->get($productId);
                    if (! $product) {
                        throw new RuntimeException("Product not found: {$productId}");
                    }
                    if ($product->stock < $qty) {
                        throw new InsufficientStockException($product->id, $product->name, $product->stock, $qty);
                    }
                }

                $order->forceFill(['stock_deducted_at' => now()])->save();

                foreach ($qtyByVariant as $variantId => $qty) {
                    $variants[$variantId]->decrement('stock', $qty);
                }

                foreach ($qtyByProduct as $productId => $qty) {
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
