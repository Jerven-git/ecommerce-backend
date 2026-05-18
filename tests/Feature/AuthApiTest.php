<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────
    // Login
    // ─────────────────────────────────────────

    public function test_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $role = Role::create(['name' => 'admin']);
        $user->roles()->attach($role);

        $this->withSession([])
            ->postJson('/api/v1/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ])->assertOk();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ])->assertStatus(422);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_non_admin_cannot_start_admin_login_flow(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // Email case normalization
    // ─────────────────────────────────────────

    public function test_user_email_is_lowercased(): void
    {
        $user = User::factory()->create(['email' => 'Test@Example.COM']);
        $this->assertEquals('test@example.com', $user->email);
    }

    public function test_user_name_is_trimmed(): void
    {
        $user = User::factory()->create(['name' => '  John Doe  ']);
        $this->assertEquals('John Doe', $user->name);
    }

    // ─────────────────────────────────────────
    // Logout
    // ─────────────────────────────────────────

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->withSession(['session_created_at' => now()->timestamp])
            ->postJson('/api/v1/logout')
            ->assertOk();
    }

    public function test_unauthenticated_cannot_logout(): void
    {
        $this->postJson('/api/v1/logout')
            ->assertStatus(401);
    }

    // ─────────────────────────────────────────
    // Protected Routes
    // ─────────────────────────────────────────

    public function test_unauthenticated_cannot_access_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard/stats')
            ->assertStatus(401);
    }
}
