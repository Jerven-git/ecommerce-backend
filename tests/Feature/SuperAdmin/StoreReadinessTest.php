<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Currency;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::factory()->base()->create(['is_enabled' => true]);
        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        $this->adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'store_id' => null,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->superAdmin->roles()->attach($superRole);
    }

    public function test_it_reports_a_ready_store_without_exposing_payment_secrets(): void
    {
        $store = app(StoreProvisioner::class)->provision([
            'name' => 'Ready Store',
            'slug' => 'ready-store',
            'status' => 'active',
            'subscription_status' => Store::SUBSCRIPTION_COMPED,
        ]);
        $this->createActiveAdmin($store);
        $secret = 'must-never-leave-the-server';

        DB::table('payment_settings')->insert([
            'store_id' => $store->id,
            'cash_enabled' => false,
            'stripe_enabled' => true,
            'paypal_enabled' => false,
            'square_enabled' => false,
            'stripe_publishable_key' => 'pk_test_ready',
            'stripe_secret_key' => $secret,
            'stripe_webhook_secret' => 'webhook-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/super-admin/stores/{$store->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.summary.required_ready', 7)
            ->assertJsonPath('data.summary.required_total', 7)
            ->assertJsonPath('data.storefront_url', 'http://ready-store.localhost')
            ->assertJsonFragment([
                'id' => 'payments',
                'state' => 'ready',
                'required' => true,
                'detail' => 'Ready providers: Stripe.',
            ]);

        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString('webhook-secret', $response->getContent());
    }

    public function test_it_reports_real_required_blockers(): void
    {
        $store = app(StoreProvisioner::class)->provision([
            'name' => 'Needs Work',
            'slug' => 'needs-work',
            'status' => 'active',
            'subscription_status' => Store::SUBSCRIPTION_UNSUBSCRIBED,
        ]);

        $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/super-admin/stores/{$store->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_attention')
            ->assertJsonPath('data.summary.required_ready', 4)
            ->assertJsonPath('data.summary.required_total', 7)
            ->assertJsonPath('data.summary.attention', 3)
            ->assertJsonFragment([
                'id' => 'active_admin',
                'state' => 'attention',
                'required' => true,
            ])
            ->assertJsonFragment([
                'id' => 'payments',
                'state' => 'attention',
                'required' => true,
            ]);
    }

    public function test_shop_specific_checks_do_not_block_a_store_with_shop_disabled(): void
    {
        $store = app(StoreProvisioner::class)->provision([
            'name' => 'Portfolio Store',
            'slug' => 'portfolio-store',
            'status' => 'active',
            'subscription_status' => Store::SUBSCRIPTION_COMPED,
        ]);
        $this->createActiveAdmin($store);
        SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->update(['modules_enabled' => ['shop' => false]]);

        $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/super-admin/stores/{$store->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.summary.required_total', 5)
            ->assertJsonFragment([
                'id' => 'payments',
                'state' => 'not_applicable',
                'required' => false,
            ]);
    }

    public function test_store_admin_cannot_view_platform_readiness(): void
    {
        $store = Store::factory()->create();
        $admin = $this->createActiveAdmin($store);

        $this->actingAs($admin)
            ->getJson("/api/v1/super-admin/stores/{$store->id}/readiness")
            ->assertForbidden();
    }

    private function createActiveAdmin(Store $store): User
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $store->id,
            'status' => User::STATUS_ACTIVE,
        ]);
        $admin->roles()->attach($this->adminRole);

        return $admin;
    }
}
