<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancellationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order Cancelled — #{$this->order->id}",
        );
    }

    public function content(): Content
    {
        $this->order->loadMissing('items');

        return new Content(
            view: 'emails.order-cancellation',
            with: [
                'customerName' => $this->order->customer_name,
                'orderId' => $this->order->id,
                'items' => $this->order->items,
                'totalAmount' => number_format((float) $this->order->total_amount, 2),
            ],
        );
    }
}
