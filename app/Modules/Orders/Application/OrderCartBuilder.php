<?php

namespace App\Modules\Orders\Application;

use App\Models\Product;
use App\Models\ProductVariant;

class OrderCartBuilder
{
    /**
     * Locks the catalog rows used by checkout and captures immutable order-item
     * data without mutating stock. Stock is deducted only after payment.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function build(array $items): CartSnapshot
    {
        $rawSubtotal = 0.0;
        $totalWeight = 0.0;
        $totalVolumeCbm = 0.0;
        $orderItems = [];
        $taxItems = [];
        $backorderItems = [];

        $products = Product::whereIn('id', array_column($items, 'product_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $variantIds = array_filter(array_column($items, 'variant_id'));
        $variants = collect();
        if ($variantIds !== []) {
            $variants = ProductVariant::with('optionValues.option')
                ->whereIn('id', $variantIds)
                ->whereIn('product_id', $products->keys())
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
        }

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $variantId = ! empty($item['variant_id']) ? (int) $item['variant_id'] : null;
            $quantity = (int) $item['quantity'];
            $product = $products->get($productId);

            if (! $product) {
                throw new OrderOperationException("Product not found: {$productId}");
            }

            $variant = $variantId ? $variants->get($variantId) : null;
            if ($variantId && ! $variant) {
                throw new OrderOperationException("Variant not found: {$variantId}");
            }
            if ($variant && (int) $variant->product_id !== $productId) {
                throw new OrderOperationException("Variant does not belong to product: {$productId}");
            }

            $availableStock = $variant ? $variant->stock : $product->stock;
            $inStockQuantity = min($quantity, $availableStock);
            $backorderQuantity = $quantity - $inStockQuantity;

            if ($backorderQuantity > 0 && ! $product->canBackorder()) {
                throw new OrderOperationException("Insufficient stock for product: {$product->name}");
            }

            $price = $variant
                ? (float) ($variant->price ?? $product->price)
                : (float) $product->price;
            $selectedOptions = $variant
                ? $variant->optionValues->map(fn ($value) => [
                    'option' => $value->option->name,
                    'value' => $value->label,
                ])->values()->toArray()
                : null;

            if ($inStockQuantity > 0) {
                $lineSubtotal = $price * $inStockQuantity;
                $rawSubtotal += $lineSubtotal;

                if ($product->shipping_calc_type === 'dimensions') {
                    $totalVolumeCbm += $product->volume_cbm * $inStockQuantity;
                } else {
                    $totalWeight += (float) ($product->weight ?? 0) * $inStockQuantity;
                }

                $orderItems[] = $this->orderItem(
                    $product,
                    $variantId,
                    $variant?->sku,
                    $selectedOptions,
                    $price,
                    $inStockQuantity,
                    $lineSubtotal,
                );
                $taxItems[] = ['price' => $price, 'quantity' => $inStockQuantity];
            }

            if ($backorderQuantity > 0) {
                $lineSubtotal = $price * $backorderQuantity;
                $orderItems[] = $this->orderItem(
                    $product,
                    $variantId,
                    $variant?->sku,
                    $selectedOptions,
                    $price,
                    $backorderQuantity,
                    $lineSubtotal,
                    backorder: true,
                );

                if ($product->backorder_charge_policy === 'charged_now') {
                    $rawSubtotal += $lineSubtotal;
                    $taxItems[] = ['price' => $price, 'quantity' => $backorderQuantity];
                }

                $backorderItems[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'quantity' => $backorderQuantity,
                    'charge_policy' => $product->backorder_charge_policy,
                ];
            }
        }

        return new CartSnapshot(
            $rawSubtotal,
            $totalWeight,
            $totalVolumeCbm,
            $orderItems,
            $taxItems,
            $backorderItems,
        );
    }

    /**
     * @param  array<int, array{option: string, value: string}>|null  $selectedOptions
     * @return array<string, mixed>
     */
    private function orderItem(
        Product $product,
        ?int $variantId,
        ?string $variantSku,
        ?array $selectedOptions,
        float $price,
        int $quantity,
        float $subtotal,
        bool $backorder = false,
    ): array {
        return [
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'variant_sku' => $variantSku,
            'selected_options' => $selectedOptions,
            'product_name' => $product->name.($backorder ? ' (Backorder)' : ''),
            'product_price' => $price,
            'quantity' => $quantity,
            'subtotal' => round($subtotal, 2),
        ];
    }
}
