<?php

namespace Tests\Feature\Tenancy;

use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $defaultStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
    }

    protected function tearDown(): void
    {
        app(CurrentStore::class)->clear();
        parent::tearDown();
    }

    public function test_site_config_is_filtered_to_the_current_store(): void
    {
        $otherStore = Store::factory()->create();

        SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $this->defaultStore->id, 'site_name' => 'Default Site']);
        SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $otherStore->id, 'site_name' => 'Other Site']);

        app(CurrentStore::class)->set($this->defaultStore);
        $this->assertSame('Default Site', SiteConfig::first()?->site_name);

        app(CurrentStore::class)->set($otherStore);
        $this->assertSame('Other Site', SiteConfig::first()?->site_name);
    }

    public function test_creating_site_config_auto_fills_current_store_id(): void
    {
        $store = Store::factory()->create();
        app(CurrentStore::class)->set($store);

        $config = SiteConfig::create(['site_name' => 'Scoped Site']);

        $this->assertSame($store->id, $config->store_id);
    }

    public function test_for_default_store_helper_returns_default_store_config_without_scope(): void
    {
        $otherStore = Store::factory()->create();

        $defaultConfig = SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $this->defaultStore->id, 'site_name' => 'Default Site']);
        SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $otherStore->id, 'site_name' => 'Other Site']);

        app(CurrentStore::class)->set($otherStore);

        $this->assertSame($defaultConfig->id, SiteConfig::forDefaultStore()?->id);
    }

    public function test_admin_request_resolves_their_store_into_current_store(): void
    {
        $store = Store::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $store->id]);
        $admin->roles()->attach($role);

        SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $this->defaultStore->id, 'backorder_enabled' => false, 'backorder_payment_link_expiry_hours' => 24]);
        SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $store->id, 'backorder_enabled' => true, 'backorder_payment_link_expiry_hours' => 72]);

        $response = $this->actingAs($admin)->getJson('/api/v1/backorder-settings');

        $response->assertOk();
        $this->assertTrue($response->json('data.backorder_enabled'));
        $this->assertSame(72, $response->json('data.backorder_payment_link_expiry_hours'));
    }

    public function test_super_admin_without_store_falls_back_to_default_store(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $super = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $super->roles()->attach($role);

        $otherStore = Store::factory()->create();
        SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $this->defaultStore->id, 'backorder_payment_link_expiry_hours' => 12]);
        SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->create(['store_id' => $otherStore->id, 'backorder_payment_link_expiry_hours' => 99]);

        $response = $this->actingAs($super)->getJson('/api/v1/backorder-settings');

        $response->assertOk();
        $this->assertSame(12, $response->json('data.backorder_payment_link_expiry_hours'));
    }

    public function test_admin_with_inactive_store_is_forbidden(): void
    {
        $store = Store::factory()->inactive()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $store->id]);
        $admin->roles()->attach($role);

        $this->actingAs($admin)
            ->getJson('/api/v1/orders')
            ->assertForbidden();
    }
}
