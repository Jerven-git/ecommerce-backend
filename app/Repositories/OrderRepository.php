<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;

class OrderRepository
{
    public function findWithPayment(int $id): Order
    {
        return Order::with('payment')->findOrFail($id);
    }

    public function findWithPaymentAndItems(int $id): Order
    {
        return Order::with(['payment', 'items'])->findOrFail($id);
    }

    public function findWithAll(int $id): Order
    {
        return Order::with(['items', 'payment', 'shipment'])->findOrFail($id);
    }

    public function restoreStock(Order $order): void
    {
        if (! $order->stock_deducted_at) {
            return;
        }

        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->variant_id) {
                ProductVariant::where('id', $item->variant_id)->increment('stock', $item->quantity);
            } else {
                Product::where('id', $item->product_id)->increment('stock', $item->quantity);
            }
        }

        $order->forceFill(['stock_deducted_at' => null])->save();
    }

    public function statusCounts(): array
    {
        return Order::toBase()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }
}
