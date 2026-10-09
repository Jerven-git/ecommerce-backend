<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreCrudTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_super_admin_can_list_stores(): void
    {
        Store::factory()->create(['name' => 'Store B']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_store_directory_is_paginated_and_searchable(): void
    {
        Store::factory()->count(30)->create();
        Store::factory()->create(['name' => 'Needle Tenant', 'slug' => 'needle-tenant']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('total', 32);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores?search=Needle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'needle-tenant');
    }

    public function test_store_directory_filters_can_be_combined(): void
    {
        Store::factory()->create([
            'name' => 'Ready Active Store',
            'status' => 'active',
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'domain' => 'ready.example.com',
            'domain_verified_at' => now(),
        ]);
        Store::factory()->create([
            'name' => 'Unverified Active Store',
            'status' => 'active',
            'subscription_status' => Store::SUBSCRIPTION_ACTIVE,
            'domain' => 'pending.example.com',
            'domain_verified_at' => null,
        ]);
        Store::factory()->create([
            'name' => 'Ready Inactive Store',
            'status' => 'inactive',
            'subscription_status' => Store::SUBSCRIPTION_COMPED,
            'domain' => null,
            'domain_verified_at' => null,
        ]);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores?status=active&subscription_status=active&domain_status=verified')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ready Active Store');

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores?domain_status=none')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Ready Inactive Store']);
    }

    public function test_store_directory_rejects_unknown_filters(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores?status=archived&domain_status=broken')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'domain_status']);
    }

    public function test_super_admin_can_load_store_options_and_store_admins(): void
    {
        $defaultStore = Store::where('slug', Store::DEFAULT_SLUG)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/store-options?search=Default')
            ->assertOk()
            ->assertJsonPath('data.0.id', $defaultStore->id);

        $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/super-admin/stores/{$defaultStore->id}/admins")
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->storeAdmin->id);
    }

    public function test_super_admin_overview_returns_aggregate_counts(): void
    {
        Store::factory()->create(['status' => 'inactive']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/overview')
            ->assertOk()
            ->assertJsonPath('data.stores.total', 2)
            ->assertJsonPath('data.stores.active', 1)
            ->assertJsonPath('data.stores.inactive', 1)
            ->assertJsonPath('data.admins.total', 2)
            ->assertJsonCount(2, 'data.newest_stores');
    }

    public function test_store_admin_cannot_list_stores(): void
    {
        $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/super-admin/stores')
            ->assertForbidden();
    }

    public function test_stores_cannot_be_created_without_their_first_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/stores', [
                'name' => 'Watch World',
                'slug' => 'watch-world',
            ])
            ->assertMethodNotAllowed();

        $this->assertDatabaseMissing('stores', ['slug' => 'watch-world']);
    }

    public function test_store_api_does_not_expose_or_mutate_deprecated_default_currency(): void
    {
        $currencyId = DB::table('currencies')->insertGetId([
            'code' => 'AUD',
            'name' => 'Australian Dollar',
            'symbol' => 'A$',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'rate' => 1,
            'is_base' => false,
            'is_enabled' => true,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $store = Store::factory()->create();
        DB::table('stores')->where('id', $store->id)->update(['default_currency_id' => $currencyId]);

        $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/super-admin/stores/{$store->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.default_currency_id');

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", [
                'name' => 'Renamed Store',
                'default_currency_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Store')
            ->assertJsonMissingPath('data.default_currency_id');

        $this->assertSame($currencyId, DB::table('stores')->where('id', $store->id)->value('default_currency_id'));
    }

    public function test_super_admin_can_set_and_clear_a_store_timezone(): void
    {
        $store = Store::factory()->create(['timezone' => null]);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['timezone' => ' Asia/Singapore '])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Singapore')
            ->assertJsonPath('data.resolved_timezone', 'Asia/Singapore');

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['timezone' => ''])
            ->assertOk()
            ->assertJsonPath('data.timezone', null)
            ->assertJsonPath('data.resolved_timezone', 'UTC');
    }

    public function test_store_timezone_must_be_a_valid_iana_identifier(): void
    {
        $store = Store::factory()->create();

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['timezone' => 'Singapore-ish'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }

    public function test_super_admin_can_deactivate_a_store(): void
    {
        $store = Store::factory()->create(['status' => 'active']);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_super_admin_cannot_deactivate_the_default_store(): void
    {
        $default = Store::where('slug', Store::DEFAULT_SLUG)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$default->id}/deactivate")
            ->assertStatus(422);
    }

    public function test_super_admin_cannot_delete_a_store_with_users(): void
    {
        $store = Store::factory()->create();
        User::factory()->create(['store_id' => $store->id]);

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/super-admin/stores/{$store->id}")
            ->assertStatus(422);
    }

    public function test_super_admin_cannot_delete_the_default_store(): void
    {
        $default = Store::where('slug', Store::DEFAULT_SLUG)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/super-admin/stores/{$default->id}")
            ->assertStatus(422);
    }
}
