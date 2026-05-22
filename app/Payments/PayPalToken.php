<?php

namespace App\Payments;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayPalToken
{
    public function __construct(
        protected PaymentCredentials $credentials,
        protected CurrentStore $currentStore,
    ) {}

    private function baseUrl(): string
    {
        return $this->credentials->get('paypal', 'mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function get(): string
    {
        $mode = (string) ($this->credentials->get('paypal', 'mode') ?: 'sandbox');
        // Cache key MUST include the store so one store's token is never served
        // to another — each store authenticates under its own PayPal app.
        $storeId = $this->currentStore->id() ?? 'default';
        $cacheKey = "paypal:access_token:{$storeId}:{$mode}";

        // Keep it simple: cache and refresh automatically.
        return Cache::remember($cacheKey, $this->defaultCacheTtl(), function () use ($cacheKey) {
            $clientId = (string) $this->credentials->get('paypal', 'client_id');
            $secret = (string) $this->credentials->get('paypal', 'secret');

            if ($clientId === '' || $secret === '') {
                throw new \RuntimeException('PayPal client_id/secret not configured');
            }

            /** @var Response $res */
            $res = Http::asForm()
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->retry(2, 200, function (\Throwable $e, ?Response $response) {
                    if ($response === null) {
                        return true;
                    }
                    $s = $response->status();

                    return $s === 429 || $s >= 500;
                })
                ->withBasicAuth($clientId, $secret)
                ->post($this->baseUrl().'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            $res->throw();

            $token = (string) ($res->json('access_token') ?? '');
            $expiresIn = (int) ($res->json('expires_in') ?? 0);

            if ($token === '') {
                throw new \RuntimeException('PayPal token response missing access_token');
            }

            // If PayPal returns expires_in, adjust cache to expire a bit earlier.
            if ($expiresIn > 0) {
                // Store it with a safety margin (e.g. 2 minutes early)
                $ttlSeconds = max(60, $expiresIn - 120);
                Cache::put(
                    $cacheKey,
                    $token,
                    now()->addSeconds($ttlSeconds)
                );

                return $token;
            }

            return $token;
        });
    }

    private function defaultCacheTtl()
    {
        // fallback if expires_in is missing
        return now()->addMinutes(50);
    }
}
