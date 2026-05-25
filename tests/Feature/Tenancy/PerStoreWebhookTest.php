<?php

namespace Tests\Feature\Tenancy;

use App\Models\PaymentSetting;
use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerStoreWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Store $storeA;

    protected Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payment.stripe.webhook_secret', null);

        $this->storeA = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
        $this->storeB = Store::factory()->create(['name' => 'Watch World', 'slug' => 'watch-world']);

        app(CurrentStore::class)->set($this->storeA);
        PaymentSetting::create(['stripe_enabled' => true, 'stripe_webhook_secret' => 'whsec_a']);

        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create(['stripe_enabled' => true, 'stripe_webhook_secret' => 'whsec_b']);

        app(CurrentStore::class)->clear();
    }

    public function test_unknown_store_slug_returns_404(): void
    {
        $this->postJson('/api/v1/webhooks/stripe/does-not-exist', [])
            ->assertStatus(404);
    }

    public function test_signature_is_verified_with_the_url_stores_secret(): void
    {
        $payload = json_encode([
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_test_1', 'metadata' => ['order_id' => '999999'], 'status' => 'succeeded']],
        ]);

        // Signed with store A's secret.
        $headerA = $this->stripeSignature($payload, 'whsec_a');

        // Hitting A's URL with A's signature is accepted (valid signature; no
        // matching payment, so it short-circuits to OK).
        $this->callWebhook('default', $payload, $headerA)->assertOk();

        // The SAME signature on store B's URL is rejected — B verifies with its
        // own secret (whsec_b), proving per-store secret resolution.
        $this->callWebhook('watch-world', $payload, $headerA)->assertStatus(400);
    }

    public function test_webhook_urls_are_returned_per_store(): void
    {
        $adminRole = \App\Models\Role::firstOrCreate(['name' => 'admin']);
        $adminB = \App\Models\User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $adminB->roles()->attach($adminRole);

        $this->actingAs($adminB)
            ->getJson('/api/v1/payment-settings')
            ->assertOk()
            ->assertJsonPath('webhook_urls.stripe', url('/api/v1/webhooks/stripe/watch-world'));
    }

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    private function callWebhook(string $storeSlug, string $payload, string $signature)
    {
        return $this->call(
            'POST',
            "/api/v1/webhooks/stripe/{$storeSlug}",
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );
    }
}
