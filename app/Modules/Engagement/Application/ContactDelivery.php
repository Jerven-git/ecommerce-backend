<?php

namespace App\Modules\Engagement\Application;

use App\Mail\ContactFormMail;
use App\Models\SiteConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class ContactDelivery
{
    public function __construct(private readonly RecaptchaVerifier $recaptcha) {}

    public function send(array $attributes): void
    {
        $this->recaptcha->verify($attributes['recaptcha_token']);
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            abort(503, 'No valid contact email configured.');
        }

        Mail::to($recipients->first())
            ->cc($recipients->slice(1)->all())
            ->send(new ContactFormMail(
                name: $attributes['name'],
                email: $attributes['email'],
                contactSubject: $attributes['subject'],
                body: $attributes['message'],
            ));
    }

    public function recipients(): Collection
    {
        $config = SiteConfig::first();
        $recipients = collect($config?->contact_entries ?? [])
            ->pluck('email')->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values();

        if ($recipients->isEmpty()) {
            $recipients = collect([$config?->contact_email ?: config('mail.from.address')])
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));
        }

        return $recipients;
    }
}
