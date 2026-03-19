<?php

namespace App\Mail;

use App\Models\Backorder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackorderCancellationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Backorder $backorder,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Backorder Cancelled - Order #{$this->backorder->order_id}",
        );
    }

    public function content(): Content
    {
        $this->backorder->loadMissing(['order', 'product']);

        return new Content(
            view: 'emails.backorder-cancellation',
            with: [
                'customerName' => $this->backorder->order->customer_name,
                'productName' => $this->backorder->product->name,
                'quantity' => $this->backorder->quantity,
                'orderId' => $this->backorder->order_id,
            ],
        );
    }
}
