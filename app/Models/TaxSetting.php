<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class TaxSetting extends Model
{
    protected $fillable = [
        'tax_enabled',
        'tax_rate',
        'tax_display_mode',
        'tax_name',
        'default_display_country',
        'default_display_state',
    ];

    protected function taxName(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? strip_tags(trim($value)) : $value,
        );
    }

    protected $casts = [
        'tax_enabled' => 'boolean',
        'tax_rate' => 'decimal:2',
    ];

    /**
     * Resolve the applicable tax rate, name, and mode for a given buyer region.
     * Checks regional tax rules first, falls back to global defaults.
     */
    public function resolveForRegion(?string $country, ?string $state): array
    {
        if (!$this->tax_enabled) {
            return [
                'rate' => 0,
                'name' => $this->tax_name ?? 'Tax',
                'mode' => $this->tax_display_mode ?? 'exclusive',
                'rule_id' => null,
                'region_label' => null,
            ];
        }

        $rule = TaxRule::forRegion($country, $state);

        if ($rule) {
            $regionLabel = match ($rule->region_type) {
                'state' => ($rule->state ? $rule->state . ', ' : '') . ($rule->country ?? ''),
                'country' => $rule->country ?? 'All regions',
                default => 'All regions',
            };

            return [
                'rate' => (float) $rule->tax_rate,
                'name' => $rule->tax_name,
                'mode' => $rule->tax_display_mode,
                'rule_id' => $rule->id,
                'region_label' => $regionLabel,
            ];
        }

        // Fall back to global defaults
        return [
            'rate' => (float) $this->tax_rate,
            'name' => $this->tax_name,
            'mode' => $this->tax_display_mode,
            'rule_id' => null,
            'region_label' => null,
        ];
    }

    public function calculateTax($basePrice)
    {
        if (!$this->tax_enabled || $this->tax_rate == 0) {
            return [
                'base_price' => $basePrice,
                'tax_amount' => 0,
                'total_price' => $basePrice,
                'display_price' => $basePrice
            ];
        }

        $taxAmount = 0;
        $totalPrice = $basePrice;
        $displayPrice = $basePrice;

        if ($this->tax_display_mode === 'inclusive') {
            // Tax is included in the price
            // tax = price - (price / (1 + rate/100))
            $taxAmount = $basePrice - ($basePrice / (1 + $this->tax_rate / 100));
            $totalPrice = $basePrice;
            $displayPrice = $basePrice;
        } else {
            // Tax is added on top
            // tax = price * (rate/100)
            $taxAmount = $basePrice * ($this->tax_rate / 100);
            $totalPrice = $basePrice + $taxAmount;
            $displayPrice = $basePrice; // Show base price, add tax at checkout
        }

        return [
            'base_price' => round($basePrice, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_price' => round($totalPrice, 2),
            'display_price' => round($displayPrice, 2),
            'tax_rate' => $this->tax_rate,
            'tax_name' => $this->tax_name,
            'tax_display_mode' => $this->tax_display_mode
        ];
    }

    /**
     * Calculate full order totals with discount and shipping in the tax base.
     * Both inclusive and exclusive modes produce the same final total.
     * Accepts optional regional overrides for rate/name/mode.
     */
    public function calculateOrderTotals(
        array $items,
        float $discountAmount = 0,
        float $shippingAmount = 0,
        ?float $overrideRate = null,
        ?string $overrideName = null,
        ?string $overrideMode = null
    ): array {
        $rate = $overrideRate ?? (float) $this->tax_rate;
        $name = $overrideName ?? $this->tax_name;
        $mode = $overrideMode ?? $this->tax_display_mode;

        $rawSubtotal = 0;
        foreach ($items as $item) {
            $rawSubtotal += $item['price'] * $item['quantity'];
        }

        if (!$this->tax_enabled || $rate == 0) {
            $exTaxSubtotal = $rawSubtotal;
            $discountedSubtotal = max(0, $exTaxSubtotal - $discountAmount);
            $taxableAmount = $discountedSubtotal + $shippingAmount;

            return [
                'raw_subtotal' => round($rawSubtotal, 2),
                'subtotal' => round($exTaxSubtotal, 2),
                'ex_tax_subtotal' => round($exTaxSubtotal, 2),
                'discounted_subtotal' => round($discountedSubtotal, 2),
                'shipping' => round($shippingAmount, 2),
                'taxable_amount' => round($taxableAmount, 2),
                'tax_amount' => 0,
                'total' => round($taxableAmount, 2),
                'tax_rate' => 0,
                'tax_name' => $name ?? 'Tax',
                'tax_display_mode' => $mode ?? 'exclusive',
            ];
        }

        // Convert to ex-tax if prices are tax-inclusive
        if ($mode === 'inclusive') {
            $exTaxSubtotal = $rawSubtotal / (1 + $rate / 100);
        } else {
            $exTaxSubtotal = $rawSubtotal;
        }

        $discountedSubtotal = max(0, $exTaxSubtotal - $discountAmount);
        $taxableAmount = $discountedSubtotal + $shippingAmount;
        $taxAmount = $taxableAmount * ($rate / 100);
        $total = $taxableAmount + $taxAmount;

        return [
            'raw_subtotal' => round($rawSubtotal, 2),
            'subtotal' => round($exTaxSubtotal, 2),
            'ex_tax_subtotal' => round($exTaxSubtotal, 2),
            'discounted_subtotal' => round($discountedSubtotal, 2),
            'shipping' => round($shippingAmount, 2),
            'taxable_amount' => round($taxableAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'total' => round($total, 2),
            'tax_rate' => $rate,
            'tax_name' => $name,
            'tax_display_mode' => $mode,
        ];
    }

    public function calculateCartTax($items)
    {
        if (!$this->tax_enabled || $this->tax_rate == 0) {
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }
            return [
                'subtotal' => round($subtotal, 2),
                'tax_amount' => 0,
                'total' => round($subtotal, 2),
                'tax_rate' => 0,
                'tax_name' => $this->tax_name ?? 'Tax',
                'tax_display_mode' => $this->tax_display_mode ?? 'exclusive',
            ];
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $price = $item['price'];
            $quantity = $item['quantity'];
            
            if ($this->tax_display_mode === 'inclusive') {
                // Price already includes tax
                $subtotal += $price * $quantity;
            } else {
                // Add base price
                $subtotal += $price * $quantity;
            }
        }

        if ($this->tax_display_mode === 'inclusive') {
            $taxAmount = $subtotal - ($subtotal / (1 + $this->tax_rate / 100));
            $baseSubtotal = $subtotal - $taxAmount;
            $total = $subtotal;
        } else {
            $taxAmount = $subtotal * ($this->tax_rate / 100);
            $baseSubtotal = $subtotal;
            $total = $subtotal + $taxAmount;
        }

        return [
            'subtotal' => round($baseSubtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total' => round($total, 2),
            'tax_rate' => $this->tax_rate,
            'tax_name' => $this->tax_name,
            'tax_display_mode' => $this->tax_display_mode
        ];
    }
}
