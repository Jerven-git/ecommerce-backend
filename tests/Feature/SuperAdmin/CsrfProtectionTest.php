<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stateful_super_admin_mutation_requires_a_csrf_token(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->app->instance('env', 'local');

        $this->actingAs($superAdmin)
            ->withHeader('Origin', 'http://localhost')
            ->postJson('/api/v1/super-admin/stores', [
                'name' => 'Protected Store',
                'slug' => 'protected-store',
                'domain' => 'protected-store.test',
            ])
            ->assertStatus(419);

        $this->assertDatabaseMissing('stores', [
            'domain' => 'protected-store.test',
        ]);
    }

    public function test_stateful_super_admin_mutation_accepts_a_matching_csrf_token(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $token = 'phase-one-csrf-token';

        $this->app->instance('env', 'local');

        $this->actingAs($superAdmin)
            ->withSession(['_token' => $token])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => $token,
            ])
            ->postJson('/api/v1/super-admin/stores', [
                'name' => 'Protected Store',
                'slug' => 'protected-store',
                'domain' => 'protected-store.test',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('stores', [
            'domain' => 'protected-store.test',
        ]);
    }

    public function test_csrf_configuration_has_no_blanket_api_exemption(): void
    {
        $excludedPaths = app(ValidateCsrfToken::class)->getExcludedPaths();

        $this->assertNotContains('api/*', $excludedPaths);
        $this->assertContains('api/v1/webhooks/*', $excludedPaths);
    }

    private function createSuperAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role);

        return $user;
    }
}
