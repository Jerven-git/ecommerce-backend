<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Subscriptions\Stripe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SubscriptionFlowApiTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $admin;

    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payment.stripe.webhook_secret', 'whsec_test');
        config()->set('app.frontend_url', 'https://spa.test');

        $this->plan = SubscriptionPlan::factory()->create([
            'slug' => 'standard-monthly',
            'name' => 'Standard',
            'price_cents' => 4900,
            'setup_fee_cents' => 9900,
            'interval' => 'monthly',
            'is_active' => true,
        ]);

        // A second, inactive plan to prove the public catalog filters it out.
        SubscriptionPlan::factory()->create(['slug' => 'legacy', 'is_active' => false]);

        $this->store = Store::factory()->unsubscribed()->create([
            'name' => 'Watch World',
            'slug' => 'watch-world',
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $this->store->id,
            'email' => 'admin@watchworld.test',
            'name' => 'Ada Watch',
        ]);
        $this->admin->roles()->attach($adminRole);
    }

    public function test_public_plans_catalog_excludes_inactive_plans(): void
    {
        $this->getJson('/api/v1/subscription/plans')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'standard-monthly')
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['slug' => 'legacy']);
    }

    public function test_gated_admin_can_read_own_subscription_status(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription_status', 'unsubscribed')
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.grace_period_days', (int) config('subscriptions.grace_period_days'));
    }

    public function test_checkout_creates_customer_and_returns_session_url(): void
    {
        $captured = null;

        $stripe = Mockery::mock(Stripe::class);
        $stripe->shouldReceive('createCustomer')->once()
            ->with('admin@watchworld.test', 'Ada Watch')
            ->andReturn('cus_test_123');
        $stripe->shouldReceive('createCheckoutSession')->once()
            ->andReturnUsing(function (array $params) use (&$captured): string {
                $captured = $params;

                return 'https://checkout.stripe.com/c/pay/xyz';
            });

        $this->app->instance(Stripe::class, $stripe);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/subscription/checkout', ['plan' => 'standard-monthly'])
            ->assertOk()
            ->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/xyz');

        $this->assertSame('subscription', $captured['mode']);
        $this->assertSame('cus_test_123', $captured['customer']);
        $this->assertSame((string) $this->store->id, $captured['client_reference_id']);
        $this->assertSame('standard-monthly', $captured['metadata']['plan_slug']);
        $this->assertSame('https://spa.test/subscribe?status=success', $captured['success_url']);
        $this->assertSame('https://spa.test/subscribe?status=cancelled', $captured['cancel_url']);

        // Combined single checkout: one one-time setup-fee line + one recurring line.
        $this->assertCount(2, $captured['line_items']);
        $this->assertSame(9900, $captured['line_items'][0]['price_data']['unit_amount']);
        $this->assertArrayNotHasKey('recurring', $captured['line_items'][0]['price_data']);
        $this->assertSame(4900, $captured['line_items'][1]['price_data']['unit_amount']);
        $this->assertSame('month', $captured['line_items'][1]['price_data']['recurring']['interval']);

        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $this->store->id,
            'status' => 'pending',
            'provider_customer_id' => 'cus_test_123',
        ]);
    }

    public function test_checkout_rejects_unknown_plan(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/subscription/checkout', ['plan' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    public function test_checkout_session_completed_webhook_activates_store(): void
    {
        $payload = [
            'id' => 'evt_act_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_shop',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'client_reference_id' => (string) $this->store->id,
                    'customer' => 'cus_test_123',
                    'subscription' => 'sub_test_1',
                    'metadata' => ['store_id' => (string) $this->store->id, 'plan_slug' => 'standard-monthly'],
                ],
            ],
        ];

        $this->callWebhook(json_encode($payload))
            ->assertOk();

        $this->store->refresh();

        $this->assertSame(Store::SUBSCRIPTION_ACTIVE, $this->store->subscription_status);
        $this->assertSame($this->plan->id, $this->store->subscription_plan_id);
        $this->assertTrue(
            $this->store->subscription_expires_at->between(
                now()->addMonths(1)->subMinute(),
                now()->addMonths(1)->addMinute()
            )
        );

        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $this->store->id,
            'provider_subscription_id' => 'sub_test_1',
            'provider_checkout_session_id' => 'cs_test_shop',
            'provider_customer_id' => 'cus_test_123',
            'status' => 'active',
            'mode' => 'initial',
        ]);
    }

    public function test_webhook_retries_are_deduplicated(): void
    {
        $payload = json_encode([
            'id' => 'evt_dup_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_dup',
                    'object' => 'checkout.session',
                    'client_reference_id' => (string) $this->store->id,
                    'customer' => 'cus_test_dup',
                    'subscription' => 'sub_test_dup',
                    'metadata' => ['store_id' => (string) $this->store->id, 'plan_slug' => 'standard-monthly'],
                ],
            ],
        ]);

        $this->callWebhook($payload)->assertOk();
        $this->callWebhook($payload)->assertOk();

        $this->assertSame(1, $this->store->subscriptions()->count());
        $this->assertSame(1, \App\Models\SubscriptionWebhookEvent::query()->count());
    }

    public function test_invoice_paid_extends_paid_window(): void
    {
        $this->activateStore('sub_renew', 'cus_renew');

        $this->store->refresh();
        $currentEnd = $this->store->subscription_expires_at;

        $newEnd = now()->addMonths(2)->startOfSecond();

        $payload = [
            'id' => 'evt_paid_1',
            'object' => 'event',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_renew_1',
                    'object' => 'invoice',
                    'customer' => 'cus_renew',
                    'subscription' => 'sub_renew',
                    'created' => now()->timestamp,
                    'lines' => [
                        'data' => [
                            ['period' => ['start' => now()->timestamp, 'end' => $newEnd->timestamp]],
                        ],
                    ],
                ],
            ],
        ];

        $this->callWebhook(json_encode($payload))->assertOk();

        $this->store->refresh();

        $this->assertGreaterThan($currentEnd, $this->store->subscription_expires_at);
        $this->assertTrue($this->store->subscription_expires_at->equalTo($newEnd));
        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $this->store->id,
            'provider_subscription_id' => 'sub_renew',
            'status' => 'active',
            'mode' => 'renewal',
        ]);
    }

    public function test_first_invoice_paid_does_not_duplicate_initial_period(): void
    {
        $this->activateStore('sub_first', 'cus_first');

        $this->store->refresh();
        $initialEnd = $this->store->subscription_expires_at;

        // The first invoice's period mirrors the activation window exactly.
        $payload = [
            'id' => 'evt_paid_first',
            'object' => 'event',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_first',
                    'object' => 'invoice',
                    'customer' => 'cus_first',
                    'subscription' => 'sub_first',
                    'lines' => [
                        'data' => [
                            ['period' => ['start' => now()->timestamp, 'end' => $initialEnd->timestamp]],
                        ],
                    ],
                ],
            ],
        ];

        $this->callWebhook(json_encode($payload))->assertOk();

        $this->store->refresh();

        $this->assertTrue($this->store->subscription_expires_at->equalTo($initialEnd));
        $this->assertSame(1, $this->store->subscriptions()->count());
    }

    public function test_subscription_cancelled_at_period_end_keeps_access_until_window_lapses(): void
    {
        $this->activateStore('sub_cancel', 'cus_cancel');

        $payload = [
            'id' => 'evt_cancel',
            'object' => 'event',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_cancel',
                    'object' => 'subscription',
                    'status' => 'canceled',
                    'cancel_at_period_end' => true,
                    'customer' => 'cus_cancel',
                    'current_period_end' => now()->addDays(10)->timestamp,
                ],
            ],
        ];

        $this->callWebhook(json_encode($payload))->assertOk();

        $this->store->refresh();

        $this->assertSame(Store::SUBSCRIPTION_CANCELLED, $this->store->subscription_status);
        $this->assertTrue($this->store->hasActiveSubscription());
        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $this->store->id,
            'provider_subscription_id' => 'sub_cancel',
            'status' => 'cancelled',
        ]);
    }

    public function test_subscription_deleted_after_window_revokes_access(): void
    {
        $this->activateStore('sub_gone', 'cus_gone');

        $payload = [
            'id' => 'evt_gone',
            'object' => 'event',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_gone',
                    'object' => 'subscription',
                    'status' => 'canceled',
                    'customer' => 'cus_gone',
                    'current_period_end' => now()->subDay()->timestamp,
                ],
            ],
        ];

        $this->callWebhook(json_encode($payload))->assertOk();

        $this->store->refresh();

        $this->assertSame(Store::SUBSCRIPTION_EXPIRED, $this->store->subscription_status);
        $this->assertFalse($this->store->hasActiveSubscription());
    }

    public function test_expire_command_flips_stores_beyond_grace_window(): void
    {
        $this->store->update([
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'subscription_plan_id' => $this->plan->id,
            'subscribed_at' => now()->subMonths(2),
            'subscription_expires_at' => now()->subDays(5),
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->store->refresh();

        $this->assertSame(Store::SUBSCRIPTION_EXPIRED, $this->store->subscription_status);
        $this->assertFalse($this->store->hasActiveSubscription());
    }

    public function test_expire_command_skips_stores_inside_grace_window(): void
    {
        $this->store->update([
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'subscription_plan_id' => $this->plan->id,
            'subscribed_at' => now()->subMonth(),
            'subscription_expires_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->store->refresh();

        $this->assertSame(Store::SUBSCRIPTION_ACTIVE, $this->store->subscription_status);
    }

    public function test_portal_requires_active_subscription(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/subscription/portal')
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('subscription_status', 'unsubscribed');
    }

    public function test_portal_returns_billing_portal_url_for_active_store(): void
    {
        $this->activateStore('sub_portal', 'cus_portal');
        $this->store->refresh();

        $stripe = Mockery::mock(Stripe::class);
        $stripe->shouldReceive('createBillingPortalSession')->once()
            ->with('cus_portal', 'https://spa.test/subscribe?status=portal')
            ->andReturn('https://billing.stripe.com/p/session/xyz');

        $this->app->instance(Stripe::class, $stripe);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/subscription/portal')
            ->assertOk()
            ->assertJsonPath('url', 'https://billing.stripe.com/p/session/xyz');
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $payload = json_encode(['id' => 'evt_bad', 'type' => 'checkout.session.completed']);

        $this->callWebhook($payload, 't=1,v1=badsignature')
            ->assertStatus(400);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Run the initial-activation path via the real webhook machinery so each
     * test exercises the full integration, not just the service.
     */
    private function activateStore(string $subscriptionId, string $customerId): void
    {
        $payload = [
            'id' => 'evt_'.$subscriptionId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_'.$subscriptionId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'client_reference_id' => (string) $this->store->id,
                    'customer' => $customerId,
                    'subscription' => $subscriptionId,
                    'metadata' => ['store_id' => (string) $this->store->id, 'plan_slug' => 'standard-monthly'],
                ],
            ],
        ];

        $this->callWebhook(json_encode($payload))->assertOk();
    }

    private function callWebhook(string $payload, ?string $signature = null)
    {
        $signature ??= $this->stripeSignature($payload, 'whsec_test');

        return $this->call(
            'POST',
            '/api/v1/webhooks/subscription/stripe',
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );
    }

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
