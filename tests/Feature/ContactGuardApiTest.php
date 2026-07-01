<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactGuardApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    public function test_enabling_contact_module_without_an_email_is_rejected(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'modules_enabled' => ['contact' => true],
                'contact_entries' => [],
                'contact_email' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_entries');
    }

    public function test_enabling_contact_module_with_a_contact_email_is_allowed(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'modules_enabled' => ['contact' => true],
                'contact_email' => 'shop@example.com',
            ])
            ->assertOk();
    }

    public function test_enabling_contact_module_with_a_contact_entry_is_allowed(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'modules_enabled' => ['contact' => true],
                'contact_entries' => [
                    ['label' => 'Sales', 'email' => 'sales@example.com'],
                ],
            ])
            ->assertOk();
    }

    public function test_clearing_the_last_contact_email_while_module_enabled_is_rejected(): void
    {
        SiteConfig::create([
            'modules_enabled' => ['contact' => true],
            'contact_email' => 'shop@example.com',
        ]);

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'contact_entries' => [],
                'contact_email' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_entries');
    }

    public function test_disabling_the_contact_module_without_an_email_is_allowed(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'modules_enabled' => ['contact' => false],
            ])
            ->assertOk();
    }

    public function test_unrelated_save_is_not_blocked_when_contact_has_no_email(): void
    {
        // Contact module is ON by default, but a save that does not touch the
        // contact module or its fields must not be blocked.
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'hero_title' => 'Welcome',
            ])
            ->assertOk();
    }
}
