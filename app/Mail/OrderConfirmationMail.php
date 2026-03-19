<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order Confirmed — #{$this->order->id}",
        );
    }

    public function content(): Content
    {
        $this->order->loadMissing('items');

        return new Content(
            view: 'emails.order-confirmation',
            with: [
                'customerName' => $this->order->customer_name,
                'orderId' => $this->order->id,
                'items' => $this->order->items,
                'subtotal' => number_format((float) $this->order->subtotal, 2),
                'taxAmount' => number_format((float) $this->order->tax_amount, 2),
                'shippingAmount' => number_format((float) $this->order->shipping_amount, 2),
                'discountAmount' => number_format((float) $this->order->discount_amount, 2),
                'totalAmount' => number_format((float) $this->order->total_amount, 2),
                'shippingAddress' => $this->order->shipping_address,
                'deliveryMethod' => $this->order->delivery_method,
                'hasDiscount' => (float) $this->order->discount_amount > 0,
            ],
        );
    }
}
