<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDisableTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $storeAdmin;

    protected Store $defaultStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $this->superAdmin->roles()->attach($superRole);

        $this->storeAdmin = User::factory()->create(['is_admin' => true, 'store_id' => $this->defaultStore->id]);
        $this->storeAdmin->roles()->attach($adminRole);
    }

    public function test_super_admin_can_disable_a_store_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/users/{$this->storeAdmin->id}", [
                'status' => User::STATUS_DISABLED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_DISABLED);

        $this->assertNotNull($this->storeAdmin->fresh()->disabled_at);
    }

    public function test_disabled_admin_cannot_use_admin_endpoints(): void
    {
        $this->storeAdmin->update([
            'status' => User::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/products', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');
    }

    public function test_disabled_admin_login_is_rejected(): void
    {
        $this->storeAdmin->update([
            'password' => 'password123',
            'status' => User::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);

        $this->withSession([])
            ->postJson('/api/v1/login', [
                'email' => $this->storeAdmin->email,
                'password' => 'password123',
            ])
            ->assertStatus(422);
    }

    public function test_cannot_disable_the_last_super_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/users/{$this->superAdmin->id}", [
                'status' => User::STATUS_DISABLED,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_disable_yourself(): void
    {
        $secondSuper = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $secondSuper->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/users/{$this->superAdmin->id}", [
                'status' => User::STATUS_DISABLED,
            ])
            ->assertStatus(422);
    }
}
