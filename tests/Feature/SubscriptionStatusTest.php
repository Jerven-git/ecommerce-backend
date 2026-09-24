<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_store_defaults_to_unsubscribed_and_gated(): void
    {
        $store = Store::create([
            'name' => 'Brand New Store',
            'slug' => 'brand-new-store',
        ]);

        $this->assertSame(Store::SUBSCRIPTION_UNSUBSCRIBED, $store->fresh()->subscription_status);
        $this->assertTrue($store->fresh()->isSubscriptionGated());
        $this->assertFalse($store->fresh()->hasActiveSubscription());

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'subscription_status' => Store::SUBSCRIPTION_UNSUBSCRIBED,
        ]);
    }

    public function test_unsubscribed_factory_state(): void
    {
        $store = Store::factory()->unsubscribed()->create();

        $this->assertSame(Store::SUBSCRIPTION_UNSUBSCRIBED, $store->subscription_status);
        $this->assertTrue($store->isSubscriptionGated());
    }

    public function test_comped_store_is_not_gated(): void
    {
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_COMPED,
        ]);

        $this->assertFalse($store->isSubscriptionGated());
        $this->assertTrue($store->hasActiveSubscription());
    }

    public function test_active_store_with_future_expiry_is_not_gated(): void
    {
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'subscription_expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse($store->isSubscriptionGated());
    }

    public function test_active_store_with_past_expiry_is_gated(): void
    {
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'subscription_expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($store->isSubscriptionGated());
    }

    public function test_active_store_without_expiry_is_not_gated(): void
    {
        // Webhook payloads may not carry a period end yet; treat as unlocked.
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'subscription_expires_at' => null,
        ]);

        $this->assertFalse($store->isSubscriptionGated());
    }

    public function test_cancelled_store_keeps_access_until_period_end(): void
    {
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_CANCELLED,
            'subscription_expires_at' => now()->addWeek(),
        ]);

        $this->assertFalse($store->isSubscriptionGated());
    }

    public function test_cancelled_store_past_period_is_gated(): void
    {
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_CANCELLED,
            'subscription_expires_at' => now()->subWeek(),
        ]);

        $this->assertTrue($store->isSubscriptionGated());
    }

    public function test_pending_store_is_gated(): void
    {
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_PENDING,
        ]);

        $this->assertTrue($store->isSubscriptionGated());
    }

    public function test_expired_store_is_gated(): void
    {
        $store = Store::factory()->create([
            'subscription_status' => Store::SUBSCRIPTION_EXPIRED,
        ]);

        $this->assertTrue($store->isSubscriptionGated());
    }

    public function test_subscription_plan_and_subscription_factories(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $subscription = Subscription::factory()->create([
            'subscription_plan_id' => $plan->id,
        ]);

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'interval' => 'monthly']);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertSame($plan->id, $subscription->subscription_plan_id);
    }

    public function test_seeder_creates_default_plan(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::query()->where('slug', 'standard')->first();

        $this->assertNotNull($plan);
        $this->assertSame('monthly', $plan->interval);
        $this->assertTrue($plan->is_active);
    }

    public function test_seeder_creates_yearly_plan(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::query()->where('slug', 'standard-yearly')->first();

        $this->assertNotNull($plan);
        $this->assertSame('yearly', $plan->interval);
        $this->assertTrue($plan->is_active);
        $this->assertSame((int) config('subscriptions.plans.standard-yearly.price_cents'), $plan->price_cents);
    }
}
