<?php

namespace App\Modules\Orders\Application;

use App\Models\Discount;
use App\Models\TaxSetting;
use App\Services\ShippingCalculator;

class OrderPricing
{
    public function __construct(private readonly ShippingCalculator $shipping) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function shipping(array $validated, CartSnapshot $cart): array
    {
        if (($validated['delivery_method'] ?? null) !== 'delivery') {
            return [
                'base_shipping' => 0,
                'weight_fee' => 0,
                'volume_fee' => 0,
                'options_fee' => 0,
                'total' => 0,
                'free_shipping' => false,
                'zone' => 'pickup',
            ];
        }

        $shipping = $this->shipping->calculateShipping([
            'country' => $validated['country'],
            'state' => $validated['state'],
            'city' => $validated['city'],
            'weight' => $cart->totalWeight,
            'volume_cbm' => $cart->totalVolumeCbm,
            'order_amount' => $cart->rawSubtotal,
            'method' => $validated['shipping_method'] ?? 'standard',
            'options' => $validated['shipping_options'] ?? [],
        ]);

        if (isset($shipping['error'])) {
            throw new OrderOperationException($shipping['error']);
        }

        return $shipping;
    }

    /** @return array{0: Discount|null, 1: float} */
    public function discount(
        ?string $code,
        float $rawSubtotal,
        ?string $country,
        ?string $state,
    ): array {
        $taxSetting = TaxSetting::first();
        $resolved = $taxSetting?->resolveForRegion($country, $state);
        $exTaxSubtotal = $rawSubtotal;
        if ($resolved && $resolved['rate'] > 0 && $resolved['mode'] === 'inclusive') {
            $exTaxSubtotal = $rawSubtotal / (1 + $resolved['rate'] / 100);
        }

        if (! $code) {
            return [null, 0.0];
        }

        $discount = Discount::where('code', $code)->lockForUpdate()->first();
        $amount = $discount && $discount->isValid($exTaxSubtotal)
            ? $discount->calculateDiscount($exTaxSubtotal)
            : 0.0;

        return [$discount, $amount];
    }

    /** @return array<string, mixed> */
    public function tax(
        CartSnapshot $cart,
        float $discountAmount,
        float $shippingAmount,
        ?string $country,
        ?string $state,
    ): array {
        $taxSetting = TaxSetting::first();

        if (! $taxSetting) {
            $rawSubtotal = array_reduce(
                $cart->taxItems,
                fn (float $subtotal, array $item) => $subtotal + ($item['price'] * $item['quantity']),
                0.0,
            );
            $discountedSubtotal = max(0, $rawSubtotal - $discountAmount);
            $taxableAmount = $discountedSubtotal + $shippingAmount;

            return [
                'raw_subtotal' => round($rawSubtotal, 2),
                'ex_tax_subtotal' => round($rawSubtotal, 2),
                'discounted_subtotal' => round($discountedSubtotal, 2),
                'shipping' => round($shippingAmount, 2),
                'taxable_amount' => round($taxableAmount, 2),
                'tax_amount' => 0,
                'total' => round($taxableAmount, 2),
                'tax_display_mode' => 'exclusive',
                'tax_rate' => 0,
                'tax_name' => 'Tax',
                'rule_id' => null,
                'region_label' => null,
            ];
        }

        $resolved = $taxSetting->resolveForRegion($country, $state);
        $result = $taxSetting->calculateOrderTotals(
            $cart->taxItems,
            $discountAmount,
            $shippingAmount,
            $resolved['rate'],
            $resolved['name'],
            $resolved['mode'],
        );
        $result['rule_id'] = $resolved['rule_id'];
        $result['region_label'] = $resolved['region_label'];

        return $result;
    }
}
