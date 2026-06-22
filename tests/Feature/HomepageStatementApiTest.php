<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageStatementApiTest extends TestCase
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

    public function test_show_returns_default_statement_shape(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.homepage_statement.enabled', false)
            ->assertJsonPath('data.homepage_statement.quote', '')
            ->assertJsonPath('data.homepage_statement.image_url', null);
    }

    public function test_admin_can_update_statement(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_statement' => [
                    'enabled' => true,
                    'eyebrow' => 'Our Promise',
                    'quote' => 'Quality you can trust, delivered fast.',
                    'attribution' => 'The Team',
                    'role' => 'Founders',
                    'cta_label' => 'About us',
                    'cta_link' => '/about',
                ],
            ])
            ->assertOk();

        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.homepage_statement.enabled', true)
            ->assertJsonPath('data.homepage_statement.quote', 'Quality you can trust, delivered fast.')
            ->assertJsonPath('data.homepage_statement.attribution', 'The Team');
    }

    public function test_statement_quote_length_is_validated(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_statement' => ['quote' => str_repeat('a', 1001)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_statement.quote');
    }

    public function test_show_returns_default_story_page_shape(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.story_page.enabled', false)
            ->assertJsonPath('data.story_page.section_a.cta_link', '/shop')
            ->assertJsonPath('data.story_page.section_b.cta_link', '/contact')
            ->assertJsonPath('data.story_page.section_a.image_url', null);
    }

    public function test_admin_can_update_story_page(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'story_page' => [
                    'enabled' => true,
                    'hero' => ['eyebrow' => 'Since 2010', 'heading' => 'Our Story', 'subtitle' => 'How we began'],
                    'section_a' => ['heading' => 'Who we are', 'body' => 'A family business.', 'image_position' => 'left'],
                    'section_b' => ['heading' => 'What we believe', 'body' => 'Customers first.'],
                ],
            ])
            ->assertOk();

        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.story_page.enabled', true)
            ->assertJsonPath('data.story_page.hero.subtitle', 'How we began')
            ->assertJsonPath('data.story_page.section_a.image_position', 'left')
            ->assertJsonPath('data.story_page.section_b.heading', 'What we believe');
    }

    public function test_story_page_image_position_is_validated(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'story_page' => ['section_a' => ['image_position' => 'middle']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('story_page.section_a.image_position');
    }
}
