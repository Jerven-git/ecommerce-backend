<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCompTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $superAdmin;

    protected User $storeAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $this->superAdmin->roles()->attach($superRole);

        $this->storeAdmin = User::factory()->create(['is_admin' => true, 'store_id' => $defaultStore->id]);
        $this->storeAdmin->roles()->attach($adminRole);

        $this->store = Store::factory()->unsubscribed()->create(['name' => 'Pending Store']);
    }

    public function test_super_admin_can_comp_an_unsubscribed_store(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$this->store->id}/comp")
            ->assertOk()
            ->assertJsonPath('data.subscription_status', 'comped');

        $this->store->refresh();

        $this->assertSame(Store::SUBSCRIPTION_COMPED, $this->store->subscription_status);
        $this->assertNull($this->store->subscription_expires_at);
        $this->assertTrue($this->store->hasActiveSubscription());
        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $this->store->id,
            'mode' => Subscription::MODE_COMP,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_comp_is_idempotent_and_logs_a_single_ledger_row(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$this->store->id}/comp")
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$this->store->id}/comp")
            ->assertOk()
            ->assertJsonPath('data.subscription_status', 'comped');

        $this->assertSame(
            1,
            $this->store->subscriptions()->where('mode', Subscription::MODE_COMP)->count()
        );
    }

    public function test_comp_can_attach_a_plan(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $this->actingAs($this->superAdmin)
            ->postJson(
                "/api/v1/super-admin/stores/{$this->store->id}/comp",
                ['subscription_plan_id' => $plan->id]
            )
            ->assertOk()
            ->assertJsonPath('data.subscription_plan.id', $plan->id);

        $this->store->refresh();
        $this->assertSame($plan->id, $this->store->subscription_plan_id);
    }

    public function test_uncomp_returns_the_store_to_unsubscribed(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$this->store->id}/comp")
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$this->store->id}/uncomp")
            ->assertOk()
            ->assertJsonPath('data.subscription_status', 'unsubscribed');

        $this->store->refresh();

        $this->assertSame(Store::SUBSCRIPTION_UNSUBSCRIBED, $this->store->subscription_status);
        $this->assertNull($this->store->subscription_plan_id);
        $this->assertTrue($this->store->isSubscriptionGated());
        $this->assertFalse($this->store->hasActiveSubscription());
        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $this->store->id,
            'mode' => Subscription::MODE_COMP,
            'status' => Subscription::STATUS_CANCELLED,
        ]);
    }

    public function test_uncomp_rejects_a_store_that_is_not_comped(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$this->store->id}/uncomp")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This store is not comped.');

        $this->store->refresh();
        $this->assertSame(Store::SUBSCRIPTION_UNSUBSCRIBED, $this->store->subscription_status);
    }

    public function test_non_super_admin_cannot_comp(): void
    {
        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/super-admin/stores/{$this->store->id}/comp")
            ->assertForbidden();

        $this->store->refresh();
        $this->assertSame(Store::SUBSCRIPTION_UNSUBSCRIBED, $this->store->subscription_status);
    }
}
