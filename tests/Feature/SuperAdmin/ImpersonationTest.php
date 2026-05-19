<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $otherSuperAdmin;

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

        $this->otherSuperAdmin = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $this->otherSuperAdmin->roles()->attach($superRole);

        $this->admin = User::factory()->create(['is_admin' => true, 'store_id' => $defaultStore->id]);
        $this->admin->roles()->attach($adminRole);
    }

    public function test_super_admin_can_start_impersonating_an_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/users/{$this->admin->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->admin->id);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'impersonation',
            'event' => 'impersonation_started',
            'causer_id' => $this->superAdmin->id,
            'subject_id' => $this->admin->id,
        ]);
    }

    public function test_super_admin_cannot_impersonate_another_super_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/users/{$this->otherSuperAdmin->id}/impersonate")
            ->assertStatus(422);
    }

    public function test_super_admin_cannot_impersonate_themselves(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/users/{$this->superAdmin->id}/impersonate")
            ->assertStatus(422);
    }

    public function test_super_admin_cannot_impersonate_disabled_user(): void
    {
        $this->admin->update(['status' => User::STATUS_DISABLED, 'disabled_at' => now()]);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/users/{$this->admin->id}/impersonate")
            ->assertStatus(422);
    }

    public function test_regular_admin_cannot_impersonate(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/super-admin/users/{$this->otherSuperAdmin->id}/impersonate")
            ->assertForbidden();
    }

    public function test_leave_impersonation_logs_an_event(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/users/{$this->admin->id}/impersonate")
            ->assertOk();

        $this->postJson('/api/v1/super-admin/impersonate/leave')
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'impersonation',
            'event' => 'impersonation_stopped',
            'causer_id' => $this->superAdmin->id,
        ]);
    }
}
