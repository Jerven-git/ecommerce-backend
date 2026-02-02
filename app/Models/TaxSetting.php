<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxSetting extends Model
{
    protected $fillable = [
        'tax_enabled',
        'tax_rate',
        'tax_display_mode',
        'tax_name',
    ];

    protected $casts = [
        'tax_enabled' => 'boolean',
        'tax_rate' => 'decimal:2',
    ];

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

    public function calculateCartTax($items)
    {
        if (!$this->tax_enabled || $this->tax_rate == 0) {
            return [
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0
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
