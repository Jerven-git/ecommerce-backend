<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeaderCtaApiTest extends TestCase
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

    public function test_show_returns_default_header_cta_shape_when_unset(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.header_cta.enabled', false)
            ->assertJsonPath('data.header_cta.label', '')
            ->assertJsonPath('data.header_cta.link', '');
    }

    public function test_admin_can_update_header_cta(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'header_cta' => [
                    'enabled' => true,
                    'label' => 'Shop Now',
                    'link' => '/shop',
                ],
            ])
            ->assertOk();

        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.header_cta.enabled', true)
            ->assertJsonPath('data.header_cta.label', 'Shop Now')
            ->assertJsonPath('data.header_cta.link', '/shop');

        $this->assertSame(
            ['enabled' => true, 'label' => 'Shop Now', 'link' => '/shop'],
            SiteConfig::first()->header_cta,
        );
    }

    public function test_header_cta_label_is_length_limited(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'header_cta' => [
                    'enabled' => true,
                    'label' => str_repeat('x', 31),
                    'link' => '/shop',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('header_cta.label');
    }
}
