<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FooterBannerApiTest extends TestCase
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

    public function test_show_returns_default_footer_banner_shape_when_unset(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.footer_banner.enabled', false)
            ->assertJsonPath('data.footer_banner.heading', '')
            ->assertJsonPath('data.footer_banner.background_color', '#111827')
            ->assertJsonPath('data.footer_banner.background_color_to', '')
            ->assertJsonPath('data.footer_banner.text_color', '#ffffff');
    }

    public function test_admin_can_update_footer_banner(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'footer_banner' => [
                    'enabled' => true,
                    'heading' => 'Summer sale on now',
                    'subtitle' => '20% off all originals',
                    'button_label' => 'Shop',
                    'button_link' => '/shop',
                    'background_color' => '#c8a45c',
                    'text_color' => '#1a1a1a',
                ],
            ])
            ->assertOk();

        $banner = SiteConfig::first()->footer_banner;
        $this->assertTrue($banner['enabled']);
        $this->assertSame('Summer sale on now', $banner['heading']);
        $this->assertSame('/shop', $banner['button_link']);
        $this->assertSame('#c8a45c', $banner['background_color']);
    }

    public function test_admin_can_update_footer_banner_with_gradient(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'footer_banner' => [
                    'enabled' => true,
                    'heading' => 'Summer sale on now',
                    'background_color' => '#111827',
                    'background_color_to' => '#b91c1c',
                    'text_color' => '#ffffff',
                ],
            ])
            ->assertOk();

        $banner = SiteConfig::first()->footer_banner;
        $this->assertSame('#111827', $banner['background_color']);
        $this->assertSame('#b91c1c', $banner['background_color_to']);
    }

    public function test_footer_banner_validates_color_format(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'footer_banner' => [
                    'enabled' => true,
                    'heading' => 'Test',
                    'background_color' => 'not-a-color',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('footer_banner.background_color');
    }

    public function test_footer_banner_validates_gradient_end_color_format(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'footer_banner' => [
                    'enabled' => true,
                    'heading' => 'Test',
                    'background_color_to' => 'not-a-color',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('footer_banner.background_color_to');
    }

    public function test_footer_banner_validates_heading_length(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'footer_banner' => [
                    'enabled' => true,
                    'heading' => str_repeat('x', 151),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('footer_banner.heading');
    }
}
