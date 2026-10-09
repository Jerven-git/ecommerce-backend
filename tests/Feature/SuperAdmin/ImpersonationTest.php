<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lab404\Impersonate\Services\ImpersonateManager;
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

    public function test_impersonation_restamps_the_session_password_hash_for_the_effective_user(): void
    {
        // Sanctum enables Laravel's AuthenticateSession middleware for SPA
        // requests; it force-logs everyone out on the next request when the
        // session's password-hash fingerprint no longer matches the effective
        // user. Impersonation swaps the user, so the controller must re-stamp
        // that fingerprint or the impersonator is silently logged out.
        $this->startSession();

        $guard = \Illuminate\Support\Facades\Auth::guard('web');
        $controller = app(\App\Http\Controllers\Api\V1\SuperAdmin\ImpersonationController::class);
        $sync = (new \ReflectionMethod($controller, 'syncSessionPasswordHash'));
        $sync->setAccessible(true);

        $sync->invoke($controller, $this->admin);
        $this->assertSame(
            $guard->hashPasswordForCookie($this->admin->getAuthPassword()),
            session('password_hash_web'),
        );

        // Leaving restores the impersonator's fingerprint.
        $sync->invoke($controller, $this->superAdmin);
        $this->assertSame(
            $guard->hashPasswordForCookie($this->superAdmin->getAuthPassword()),
            session('password_hash_web'),
        );
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

    public function test_failed_start_restores_the_super_admin_session(): void
    {
        $manager = \Mockery::mock(ImpersonateManager::class);
        $manager->shouldReceive('isImpersonating')->once()->andReturnFalse();
        $manager->shouldReceive('getCurrentAuthGuardName')->once()->andReturn('web');
        $manager->shouldReceive('take')->once()->withArgs(function (User $from, User $to, string $guard): bool {
            return $from->is($this->superAdmin) && $to->is($this->admin) && $guard === 'web';
        })->andReturnUsing(function (): bool {
            session()->put(config('laravel-impersonate.session_key'), $this->superAdmin->id);
            session()->put(config('laravel-impersonate.session_guard'), 'web');
            session()->put(config('laravel-impersonate.session_guard_using'), 'web');
            \Illuminate\Support\Facades\Auth::guard('web')->quietLogout();

            return false;
        });
        $manager->shouldReceive('clear')->once()->andReturnUsing(function (): void {
            session()->forget([
                config('laravel-impersonate.session_key'),
                config('laravel-impersonate.session_guard'),
                config('laravel-impersonate.session_guard_using'),
            ]);
        });
        $this->app->instance(ImpersonateManager::class, $manager);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/users/{$this->admin->id}/impersonate")
            ->assertStatus(500)
            ->assertJsonPath('code', 'impersonation_start_failed')
            ->assertSessionMissing(config('laravel-impersonate.session_key'));

        $this->assertAuthenticatedAs($this->superAdmin, 'web');
        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'impersonation',
            'event' => 'impersonation_started',
        ]);
    }

    public function test_failed_leave_falls_back_to_restoring_the_super_admin(): void
    {
        $manager = \Mockery::mock(ImpersonateManager::class);
        $manager->shouldReceive('isImpersonating')->once()->andReturnTrue();
        $manager->shouldReceive('getImpersonatorGuardName')->once()->andReturn('web');
        $manager->shouldReceive('leave')->once()->andReturnFalse();
        $manager->shouldReceive('clear')->once()->andReturnUsing(function (): void {
            session()->forget([
                config('laravel-impersonate.session_key'),
                config('laravel-impersonate.session_guard'),
                config('laravel-impersonate.session_guard_using'),
            ]);
        });
        $this->app->instance(ImpersonateManager::class, $manager);

        $this->actingAs($this->admin)
            ->withSession([
                config('laravel-impersonate.session_key') => $this->superAdmin->id,
                config('laravel-impersonate.session_guard') => 'web',
                config('laravel-impersonate.session_guard_using') => 'web',
            ])
            ->postJson('/api/v1/super-admin/impersonate/leave')
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->superAdmin->id)
            ->assertSessionMissing(config('laravel-impersonate.session_key'));

        $this->assertAuthenticatedAs($this->superAdmin, 'web');
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'impersonation',
            'event' => 'impersonation_stopped',
            'causer_id' => $this->superAdmin->id,
            'subject_id' => $this->admin->id,
        ]);
    }

    public function test_leave_terminates_the_session_when_the_originating_account_was_disabled(): void
    {
        $this->superAdmin->update([
            'status' => User::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->withSession([
                config('laravel-impersonate.session_key') => $this->superAdmin->id,
                config('laravel-impersonate.session_guard') => 'web',
                config('laravel-impersonate.session_guard_using') => 'web',
            ])
            ->postJson('/api/v1/super-admin/impersonate/leave')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'impersonation_source_unavailable')
            ->assertSessionMissing(config('laravel-impersonate.session_key'));

        $this->assertGuest('web');
    }

    public function test_disabled_impersonated_user_can_still_leave_impersonation(): void
    {
        $this->admin->update([
            'status' => User::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->withSession([
                config('laravel-impersonate.session_key') => $this->superAdmin->id,
                config('laravel-impersonate.session_guard') => 'web',
                config('laravel-impersonate.session_guard_using') => 'web',
            ])
            ->postJson('/api/v1/super-admin/impersonate/leave')
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->superAdmin->id)
            ->assertSessionMissing(config('laravel-impersonate.session_key'));

        $this->assertAuthenticatedAs($this->superAdmin, 'web');
    }

    public function test_disabled_impersonated_user_can_rehydrate_the_recovery_state(): void
    {
        $this->admin->update([
            'status' => User::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->withSession([
                config('laravel-impersonate.session_key') => $this->superAdmin->id,
                config('laravel-impersonate.session_guard') => 'web',
                config('laravel-impersonate.session_guard_using') => 'web',
            ])
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('user.id', $this->admin->id)
            ->assertJsonPath('user.status', User::STATUS_DISABLED)
            ->assertJsonPath('user.is_impersonating', true)
            ->assertJsonPath('user.impersonator.id', $this->superAdmin->id);
    }
}
