<?php

namespace Tests\Feature\Tenancy;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\PaymentWebhookEvent;
use App\Models\Store;
use App\Payments\PaymentService;
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

    public function test_stripe_payment_update_is_idempotent_by_provider_event_id(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        $order = Order::factory()->create(['store_id' => $this->storeA->id]);
        $payment = app(PaymentService::class)->createPending($order, 'stripe', [
            'provider_ref' => 'pi_webhook_contract',
        ]);

        $payload = json_encode([
            'id' => 'evt_webhook_contract',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_webhook_contract',
                'metadata' => ['order_id' => (string) $order->id],
                'status' => 'succeeded',
            ]],
        ]);
        $signature = $this->stripeSignature($payload, 'whsec_a');

        $this->callWebhook('default', $payload, $signature)->assertOk();
        $this->callWebhook('default', $payload, $signature)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(1, PaymentWebhookEvent::where([
            'provider' => 'stripe',
            'event_id' => 'evt_webhook_contract',
        ])->count());
    }

    public function test_square_webhook_updates_the_matching_payment(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        PaymentSetting::first()->update([
            'square_enabled' => true,
            'square_webhook_secret' => 'square_secret_a',
        ]);
        $order = Order::factory()->create(['store_id' => $this->storeA->id]);
        $payment = app(PaymentService::class)->createPending($order, 'square', [
            'provider_ref' => 'square-order-contract',
        ]);

        $payload = json_encode([
            'event_id' => 'square-event-contract',
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'square-payment-contract',
                'order_id' => 'square-order-contract',
                'status' => 'COMPLETED',
            ]]],
        ]);
        $url = url('/api/v1/webhooks/square/default');
        $signature = base64_encode(hash_hmac('sha256', $url.$payload, 'square_secret_a', true));

        $this->call(
            'POST',
            '/api/v1/webhooks/square/default',
            [], [], [],
            ['HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        )->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('square-payment-contract', $payment->fresh()->meta['square_payment_id']);
    }

    public function test_webhook_cannot_update_a_payment_from_another_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        $orderA = Order::factory()->create(['store_id' => $this->storeA->id]);
        $paymentA = app(PaymentService::class)->createPending($orderA, 'stripe', [
            'provider_ref' => 'pi_shared_reference',
        ]);

        app(CurrentStore::class)->set($this->storeB);
        $orderB = Order::factory()->create(['store_id' => $this->storeB->id]);
        $paymentB = app(PaymentService::class)->createPending($orderB, 'stripe', [
            'provider_ref' => 'pi_shared_reference',
        ]);

        $payload = json_encode([
            'id' => 'evt_store_boundary',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_shared_reference',
                'metadata' => [],
                'status' => 'succeeded',
            ]],
        ]);

        $this->callWebhook('default', $payload, $this->stripeSignature($payload, 'whsec_a'))->assertOk();

        $this->assertSame('paid', $paymentA->fresh()->status);
        $this->assertSame('pending', $paymentB->fresh()->status);
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
