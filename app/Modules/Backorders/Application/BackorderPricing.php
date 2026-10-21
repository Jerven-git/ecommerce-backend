<?php

namespace App\Modules\Backorders\Application;

use App\Models\Backorder;
use App\Models\Product;
use App\Models\TaxSetting;
use App\Services\ShippingCalculator;

class BackorderPricing
{
    public function __construct(private readonly ShippingCalculator $shipping) {}

    /** @return array{subtotal: float, tax_amount: float, tax_rate: float, tax_name: string, shipping_amount: float|int, total: float} */
    public function calculate(Backorder $backorder): array
    {
        $product = $backorder->product;
        $subtotal = round((float) $product->price * $backorder->quantity, 2);
        $tax = $this->tax($product, $backorder->quantity);
        $shipping = $this->shipping($backorder, $subtotal);

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $tax['tax_amount'],
            'tax_rate' => $tax['tax_rate'],
            'tax_name' => $tax['tax_name'],
            'shipping_amount' => $shipping['total'],
            'total' => round($subtotal + $tax['tax_amount'] + $shipping['total'], 2),
        ];
    }

    /** @return array<string, mixed> */
    private function shipping(Backorder $backorder, float $subtotal): array
    {
        $order = $backorder->order;
        if (! $order || $order->delivery_method === 'pickup' || ! $order->country) {
            return ['total' => 0];
        }

        $product = $backorder->product;
        $weight = 0;
        $volumeCbm = 0;

        if ($product->shipping_calc_type === 'dimensions') {
            $volumeCbm = $product->volume_cbm * $backorder->quantity;
        } else {
            $weight = (float) ($product->weight ?? 0) * $backorder->quantity;
        }

        $result = $this->shipping->calculateShipping([
            'country' => $order->country,
            'state' => $order->state,
            'city' => $order->city,
            'weight' => $weight,
            'volume_cbm' => $volumeCbm,
            'order_amount' => $subtotal,
            'options' => [],
        ]);

        return isset($result['error']) ? ['total' => 0] : $result;
    }

    /** @return array{tax_amount: float|int, tax_rate: float|int, tax_name: string} */
    private function tax(Product $product, int $quantity): array
    {
        $setting = TaxSetting::first();
        if (! $setting || ! $setting->tax_enabled || $setting->tax_rate == 0) {
            return [
                'tax_amount' => 0,
                'tax_rate' => 0,
                'tax_name' => $setting?->tax_name ?? 'Tax',
            ];
        }

        $result = $setting->calculateCartTax([[
            'price' => (float) $product->price,
            'quantity' => $quantity,
        ]]);

        return [
            'tax_amount' => (float) ($result['tax_amount'] ?? 0),
            'tax_rate' => (float) ($result['tax_rate'] ?? 0),
            'tax_name' => $result['tax_name'] ?? 'Tax',
        ];
    }
}
