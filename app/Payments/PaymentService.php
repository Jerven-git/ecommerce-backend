<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';

    public const ORDER_STATUS_PENDING = 'pending';
    public const ORDER_STATUS_PROCESSING = 'processing';

    public function createPending(Order $order, string $provider, array $init): Payment
    {
        return Payment::create([
            'order_id'      => $order->id,
            'provider'      => $provider,
            'provider_ref'  => $init['provider_ref'] ?? null,
            'status'        => self::PAYMENT_STATUS_PENDING,
            'amount'        => $this->toCents($order->total_amount),
            'currency'      => strtoupper($order->currency ?: 'USD'),
            'meta'          => $init,
        ]);
    }

    public function markPaid(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent: already paid
            if ($payment->status === self::PAYMENT_STATUS_PAID) {
                return;
            }

            $payment->update(['status' => self::PAYMENT_STATUS_PAID]);

            if (!$payment->order_id) {
                return;
            }

            $order = Order::query()
                ->whereKey($payment->order_id)
                ->with('items')
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return;
            }

            // If already deducted, just ensure status is set and exit
            if ($order->stock_deducted_at) {
                if ($order->status === self::ORDER_STATUS_PENDING) {
                    $order->forceFill(['status' => self::ORDER_STATUS_PROCESSING])->save();
                }
                return;
            }

            // Calculate required quantities per product
            $qtyByProduct = $order->items
                ->groupBy('product_id')
                ->map(fn ($rows) => (int) $rows->sum('quantity'));

            if ($qtyByProduct->isEmpty()) {
                // No items — still mark order processing if it was pending
                $order->forceFill([
                    'stock_deducted_at' => now(),
                    'status' => $order->status === self::ORDER_STATUS_PENDING
                        ? self::ORDER_STATUS_PROCESSING
                        : $order->status,
                ])->save();
                return;
            }

            // Lock all relevant products in one query
            $products = Product::query()
                ->whereIn('id', $qtyByProduct->keys()->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Validate stock before mutating anything
            foreach ($qtyByProduct as $productId => $qty) {
                $product = $products->get($productId);

                if (!$product) {
                    throw new RuntimeException("Product not found: {$productId}");
                }

                if ($product->stock < $qty) {
                    throw new InsufficientStockException($product->id, $product->name, $product->stock, $qty);
                }
            }

            // Mark order first (still in transaction) then decrement stock
            $order->forceFill([
                'stock_deducted_at' => now(),
                'status' => $order->status === self::ORDER_STATUS_PENDING
                    ? self::ORDER_STATUS_PROCESSING
                    : $order->status,
            ])->save();

            foreach ($qtyByProduct as $productId => $qty) {
                $products[$productId]->decrement('stock', $qty);
            }
        });
    }

    /**
     * Convert to integer cents safely.
     * Accepts decimal string/float/int.
     */
    private function toCents($amount): int
    {
        // Best: if you can guarantee decimal string, this is safer than float math.
        // Example: "12.34" => 1234
        $normalized = number_format((float) $amount, 2, '.', '');
        return (int) str_replace('.', '', $normalized);
    }
}