<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LoaderAppearanceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $this->admin->roles()->attach($role);
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    public function test_loader_appearance_defaults_are_returned(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.loader_animation', 'bounce')
            ->assertJsonPath('data.loader_logo_url', null);
    }

    public function test_admin_can_update_loader_animation(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', ['loader_animation' => 'slide'])
            ->assertOk()
            ->assertJsonPath('data.loader_animation', 'slide');

        $this->assertSame('slide', SiteConfig::firstOrFail()->loader_animation);
    }

    public function test_loader_animation_rejects_unknown_values(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', ['loader_animation' => 'spin-fast'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('loader_animation');
    }

    public function test_admin_can_upload_and_remove_a_loader_logo(): void
    {
        Storage::fake('public');

        $upload = $this->asAdmin()->post('/api/v1/site-config/media/loader_logo', [
            'file' => UploadedFile::fake()->image('loader-logo.png', 120, 80),
        ], ['Accept' => 'application/json']);

        $upload->assertOk()
            ->assertJsonPath('collection', 'loader_logo');

        $this->asAdmin()
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.loader_logo_url', $upload->json('url'));

        $this->asAdmin()
            ->deleteJson('/api/v1/site-config/media/loader_logo')
            ->assertNoContent();

        $this->asAdmin()
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.loader_logo_url', null);
    }
}
