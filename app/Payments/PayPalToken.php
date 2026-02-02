<?php

namespace App\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayPalToken
{
    private function baseUrl(): string
    {
        return config('payment.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function get(): string
    {
        $cacheKey = 'paypal:access_token:' . config('payment.paypal.mode');

        // Cache for ~50 minutes (safe default).
        return Cache::remember($cacheKey, now()->addMinutes(50), function () {
            /** @var Response $res */
            $res = Http::asForm()
                ->connectTimeout(3)
                ->timeout(8)
                ->retry(2, 200)
                ->withBasicAuth(
                    config('payment.paypal.client_id'),
                    config('payment.paypal.secret')
                )
                ->post($this->baseUrl() . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            $res->throw();

            $token = $res->json('access_token');

            if (!$token) {
                throw new \RuntimeException('PayPal token response missing access_token');
            }

            return $token;
        });
    }
}
