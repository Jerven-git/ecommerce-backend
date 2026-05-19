<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'admin']);
        $superAdminRole = Role::create(['name' => 'super_admin']);

        $this->superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'super@example.com',
        ]);
        $this->superAdmin->roles()->attach($superAdminRole);

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);
        $this->admin->roles()->attach($adminRole);
    }

    public function test_super_admin_can_list_admin_users(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_regular_admin_cannot_list_admin_users(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403);
    }

    public function test_super_admin_can_create_admin_user(): void
    {
        \App\Models\Store::firstOrCreate(
            ['slug' => \App\Models\Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'New Admin',
                'email' => 'new-admin@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'admin',
                'store_name' => 'Acme Watches',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'new-admin@example.com')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.store.name', 'Acme Watches');

        $this->assertDatabaseHas('stores', ['name' => 'Acme Watches']);
    }

    public function test_role_changes_via_update_are_rejected(): void
    {
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/admin/users/{$this->admin->id}", [
                'role' => 'super_admin',
            ])
            ->assertStatus(422);
    }

    public function test_cannot_delete_last_super_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/admin/users/{$this->superAdmin->id}")
            ->assertStatus(422);
    }

    public function test_cannot_demote_last_super_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/admin/users/{$this->superAdmin->id}", [
                'role' => 'admin',
            ])
            ->assertStatus(422);
    }
}
