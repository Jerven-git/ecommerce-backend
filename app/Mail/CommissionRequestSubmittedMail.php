<?php

namespace App\Mail;

use App\Models\CommissionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CommissionRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CommissionRequest $commission) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New commission request: {$this->commission->title}",
            replyTo: [$this->commission->customer_email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.commissions.submitted',
            with: ['commission' => $this->commission],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
