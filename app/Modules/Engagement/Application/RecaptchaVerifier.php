<?php

namespace App\Modules\Engagement\Application;

use Illuminate\Support\Facades\Http;

class RecaptchaVerifier
{
    public function verify(?string $token): void
    {
        $secret = config('services.recaptcha.secret_key');
        if (! $secret || ! $token) {
            return;
        }

        $result = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
        ])->json();

        if (! ($result['success'] ?? false)
            || ($result['score'] ?? 0) < config('services.recaptcha.threshold', 0.5)) {
            abort(422, 'reCAPTCHA verification failed. Please try again.');
        }
    }
}
