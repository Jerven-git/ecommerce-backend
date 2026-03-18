<?php

namespace App\Mail;

use App\Models\Backorder;
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
        return new Content(
            view: 'emails.backorder-payment-link',
            with: [
                'customerName' => $this->backorder->order->customer_name,
                'productName' => $this->backorder->product->name,
                'quantity' => $this->backorder->quantity,
                'price' => number_format((float) $this->backorder->product->price * $this->backorder->quantity, 2),
                'paymentUrl' => config('app.frontend_url', config('app.url')) . '/backorder/pay/' . $this->token,
                'expiryHours' => $this->expiryHours,
                'orderId' => $this->backorder->order_id,
            ],
        );
    }
}
