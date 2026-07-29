<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_super_admin_can_create_a_store(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/stores', [
                'name' => 'Watch World',
                'slug' => 'watch-world',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'watch-world');

        $this->assertDatabaseHas('stores', ['slug' => 'watch-world', 'status' => 'active']);
    }

    public function test_super_admin_cannot_create_a_store_with_duplicate_slug(): void
    {
        Store::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/stores', [
                'name' => 'Other',
                'slug' => 'taken',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
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
