<?php

namespace Tests\Feature\Tenancy;

use App\Models\PaymentSetting;
use App\Models\Store;
use App\Payments\PayPalToken;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalTokenScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $storeA;

    protected Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
        $this->storeB = Store::factory()->create(['name' => 'Watch World']);

        app(CurrentStore::class)->set($this->storeA);
        PaymentSetting::create(['paypal_client_id' => 'client_a', 'paypal_secret' => 'secret_a', 'paypal_mode' => 'sandbox']);

        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create(['paypal_client_id' => 'client_b', 'paypal_secret' => 'secret_b', 'paypal_mode' => 'sandbox']);

        // Return a token derived from the basic-auth client id, so each store's
        // credentials yield a distinct token.
        Http::fake(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';
            $decoded = base64_decode((string) str_replace('Basic ', '', $auth));
            $clientId = explode(':', $decoded)[0] ?? 'unknown';

            return Http::response(['access_token' => "tok_for_{$clientId}", 'expires_in' => 3000], 200);
        });
    }

    public function test_each_store_gets_a_token_from_its_own_credentials(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        $this->assertSame('tok_for_client_a', app(PayPalToken::class)->get());

        app(CurrentStore::class)->set($this->storeB);
        $this->assertSame('tok_for_client_b', app(PayPalToken::class)->get());
    }

    public function test_one_stores_cached_token_is_never_served_to_another(): void
    {
        // Prime store A's token (cached under A's store-scoped key).
        app(CurrentStore::class)->set($this->storeA);
        $this->assertSame('tok_for_client_a', app(PayPalToken::class)->get());

        // Store B must get ITS own token, not A's cached one.
        app(CurrentStore::class)->set($this->storeB);
        $this->assertSame('tok_for_client_b', app(PayPalToken::class)->get());

        // Back to A — still A's.
        app(CurrentStore::class)->set($this->storeA);
        $this->assertSame('tok_for_client_a', app(PayPalToken::class)->get());
    }
}
