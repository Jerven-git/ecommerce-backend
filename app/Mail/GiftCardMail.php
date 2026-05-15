<?php

namespace App\Mail;

use App\Models\GiftCard;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GiftCardMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public GiftCard $giftCard,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You received a Gift Card!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.gift-card',
            with: [
                'recipientName' => $this->giftCard->recipient_name ?? $this->giftCard->recipient_email,
                'senderName' => $this->giftCard->purchaser_name,
                'personalMessage' => $this->giftCard->message,
                'code' => $this->giftCard->code,
                'amount' => number_format((float) $this->giftCard->original_amount, 2),
                'currency' => $this->giftCard->currency,
            ],
        );
    }
}
