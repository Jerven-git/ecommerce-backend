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

    public function test_admin_role_requires_a_store(): void
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
            ->assertJsonPath('message', 'Select an existing store to assign this admin to, or provide a name for a new store.');
    }

    public function test_admin_can_be_assigned_to_an_existing_store_without_creating_one(): void
    {
        $countBefore = Store::count();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/users', [
                'name' => 'Second Admin',
                'email' => 'second@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'admin',
                'store_id' => $this->storeB->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.store.id', $this->storeB->id);

        $this->assertSame($countBefore, Store::count());
        $this->assertSame($this->storeB->id, User::where('email', 'second@example.com')->first()->store_id);
    }

    public function test_a_store_can_have_multiple_admins(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $first = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $first->roles()->attach($adminRole);

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/super-admin/users', [
                'name' => 'Co Admin',
                'email' => 'co@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'admin',
                'store_id' => $this->storeB->id,
            ])
            ->assertCreated();

        $this->assertSame(2, $this->storeB->users()->count());
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

    public function test_admin_store_assignment_can_be_changed_via_update(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/users/{$admin->id}", [
                'store_id' => $this->defaultStore->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.store.id', $this->defaultStore->id);

        $this->assertSame($this->defaultStore->id, $admin->fresh()->store_id);
    }

    public function test_super_admin_cannot_be_assigned_to_a_store_via_update(): void
    {
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/users/{$this->superAdmin->id}", [
                'store_id' => $this->storeB->id,
            ])
            ->assertStatus(422);

        $this->assertNull($this->superAdmin->fresh()->store_id);
    }

    public function test_deleting_an_admin_keeps_the_store_when_other_admins_remain(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $keep = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $keep->roles()->attach($adminRole);
        $remove = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $remove->roles()->attach($adminRole);

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/super-admin/users/{$remove->id}")
            ->assertOk();

        $this->assertNotSoftDeleted($this->storeB);
        $this->assertSame(1, $this->storeB->users()->count());
    }

    public function test_deleting_the_last_admin_soft_deletes_the_store(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $only = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $only->roles()->attach($adminRole);

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/super-admin/users/{$only->id}")
            ->assertOk();

        $this->assertSoftDeleted($this->storeB);
    }
}
