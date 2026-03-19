<?php

namespace App\Mail;

use App\Models\Backorder;
use App\Models\Product;
use App\Models\TaxSetting;
use App\Services\ShippingCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackorderPaymentLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Backorder $backorder,
        public string $token,
        public int $expiryHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your backordered item is now available!",
        );
    }

    public function content(): Content
    {
        $this->backorder->loadMissing(['order', 'product']);

        $product = $this->backorder->product;
        $order = $this->backorder->order;
        $quantity = $this->backorder->quantity;

        $subtotal = round((float) $product->price * $quantity, 2);
        $tax = $this->calculateTax($product, $quantity);
        $shipping = $this->calculateShipping();
        $total = round($subtotal + $tax['tax_amount'] + $shipping, 2);

        return new Content(
            view: 'emails.backorder-payment-link',
            with: [
                'customerName' => $order->customer_name,
                'productName' => $product->name,
                'quantity' => $quantity,
                'subtotal' => number_format($subtotal, 2),
                'taxAmount' => number_format($tax['tax_amount'], 2),
                'taxName' => $tax['tax_name'],
                'hasTax' => $tax['tax_amount'] > 0,
                'shippingAmount' => number_format($shipping, 2),
                'hasShipping' => $shipping > 0,
                'total' => number_format($total, 2),
                'paymentUrl' => config('app.frontend_url', config('app.url')) . '/backorder/pay/' . $this->token,
                'expiryHours' => $this->expiryHours,
                'orderId' => $this->backorder->order_id,
            ],
        );
    }

    private function calculateTax(Product $product, int $quantity): array
    {
        $taxSetting = TaxSetting::first();

        if (!$taxSetting || !$taxSetting->tax_enabled || $taxSetting->tax_rate == 0) {
            return [
                'tax_amount' => 0,
                'tax_name' => $taxSetting?->tax_name ?? 'Tax',
            ];
        }

        $items = [['price' => (float) $product->price, 'quantity' => $quantity]];
        $result = $taxSetting->calculateCartTax($items);

        return [
            'tax_amount' => (float) ($result['tax_amount'] ?? 0),
            'tax_name' => $result['tax_name'] ?? 'Tax',
        ];
    }

    private function calculateShipping(): float
    {
        $order = $this->backorder->order;

        if (!$order || $order->delivery_method === 'pickup' || !$order->country) {
            return 0;
        }

        $product = $this->backorder->product;
        $quantity = $this->backorder->quantity;
        $weight = 0;
        $volumeCbm = 0;

        if ($product->shipping_calc_type === 'dimensions') {
            $volumeCbm = $product->volume_cbm * $quantity;
        } else {
            $weight = (float) ($product->weight ?? 0) * $quantity;
        }

        $lineTotal = round((float) $product->price * $quantity, 2);

        $calculator = app(ShippingCalculator::class);
        $result = $calculator->calculateShipping([
            'country' => $order->country,
            'state' => $order->state,
            'city' => $order->city,
            'weight' => $weight,
            'volume_cbm' => $volumeCbm,
            'subtotal' => $lineTotal,
        ]);

        return (float) ($result['total'] ?? 0);
    }
}
