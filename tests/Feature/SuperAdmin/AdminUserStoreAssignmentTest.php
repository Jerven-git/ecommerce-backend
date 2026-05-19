<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserStoreAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected Store $defaultStore;

    protected Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
        $this->storeB = Store::factory()->create();

        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $this->superAdmin->roles()->attach($superRole);
    }

    public function test_admin_role_requires_store_name(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/users', [
                'name' => 'Newbie',
                'email' => 'newbie@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'admin',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_name');
    }

    public function test_super_admin_role_does_not_create_a_store(): void
    {
        $countBefore = \App\Models\Store::count();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/users', [
                'name' => 'New Super',
                'email' => 'super2@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'super_admin',
            ])
            ->assertCreated();

        $this->assertNull(User::where('email', 'super2@example.com')->first()->store_id);
        $this->assertSame($countBefore, \App\Models\Store::count());
    }

    public function test_creating_admin_atomically_creates_a_new_store_with_default_site_config(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/users', [
                'name' => 'Watch World Admin',
                'email' => 'ww@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'admin',
                'store_name' => 'Watch World',
            ])
            ->assertCreated()
            ->assertJsonPath('data.store.name', 'Watch World');

        $store = \App\Models\Store::where('name', 'Watch World')->firstOrFail();
        $this->assertSame('watch-world', $store->slug);

        $config = \App\Models\SiteConfig::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('store_id', $store->id)
            ->firstOrFail();
        $this->assertSame('My Store', $config->site_name);
    }

    public function test_admin_store_assignment_cannot_be_changed_via_update(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $admin->roles()->attach(\App\Models\Role::firstOrCreate(['name' => 'admin']));

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/users/{$admin->id}", [
                'store_id' => $this->defaultStore->id,
            ])
            ->assertStatus(422);

        $this->assertSame($this->storeB->id, $admin->fresh()->store_id);
    }
}
