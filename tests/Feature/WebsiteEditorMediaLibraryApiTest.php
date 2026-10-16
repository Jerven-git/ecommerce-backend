<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SubscriptionMiddleware;
use App\Models\Media;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigVersion;
use App\Models\Store;
use App\Models\User;
use App\Support\WebsiteEditor\HomepageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteEditorMediaLibraryApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private SiteConfig $config;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->store = Store::factory()->create();
        $this->config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
        ]);
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $this->store->id,
        ]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
    }

    private function asAdmin(?User $user = null): self
    {
        return $this->actingAs($user ?? $this->admin)
            ->withoutMiddleware([
                SessionLifetimeMiddleware::class,
                SubscriptionMiddleware::class,
            ]);
    }

    private function media(SiteConfig $config, string $collection, string $name = 'cover.jpg', string $mime = 'image/jpeg', int $size = 1024): Media
    {
        $path = 'site-config/'.uniqid('', true).'.jpg';
        Storage::disk('public')->put($path, 'media-bytes');

        return $config->media()->create([
            'collection' => $collection,
            'hash' => md5($path),
            'path' => $path,
            'format' => 'jpg',
            'mime_type' => $mime,
            'size' => $size,
            'processing_status' => 'ready',
            'original_name' => $name,
        ]);
    }

    public function test_library_lists_only_authenticated_store_assets_with_usage(): void
    {
        $asset = $this->media($this->config, 'about', 'team-cover.jpg');
        $otherStore = Store::factory()->create();
        $otherConfig = SiteConfig::withoutGlobalScope(StoreScope::class)->create(['store_id' => $otherStore->id]);
        $foreign = $this->media($otherConfig, 'about', 'foreign.jpg');

        $response = $this->asAdmin()->getJson('/api/v1/site-config/media-library?slot=blog')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $asset->id)
            ->assertJsonPath('data.0.name', 'team-cover.jpg')
            ->assertJsonPath('data.0.status', 'live')
            ->assertJsonPath('data.0.can_delete', false);

        $this->assertContains('Live · About cover', $response->json('data.0.usage'));
        $this->assertNotContains($foreign->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_reusing_an_asset_across_slots_creates_an_independent_private_copy(): void
    {
        $source = $this->media($this->config, 'about', 'shared-cover.jpg');

        $response = $this->asAdmin()
            ->postJson("/api/v1/site-config/media-library/{$source->id}/use/blog")
            ->assertOk()
            ->assertJsonPath('collection', 'blog')
            ->assertJsonPath('mime_type', 'image/jpeg');

        $copy = Media::findOrFail($response->json('id'));
        $this->assertNotSame($source->id, $copy->id);
        $this->assertNotSame($source->path, $copy->path);
        $this->assertSame('website_editor_draft_blog', $copy->collection);
        $this->assertSame('shared-cover.jpg', $copy->original_name);
        Storage::disk('public')->assertExists($source->path);
        Storage::disk('public')->assertExists($copy->path);
    }

    public function test_library_reuse_and_metadata_updates_cannot_cross_store_boundaries(): void
    {
        $otherStore = Store::factory()->create();
        $otherConfig = SiteConfig::withoutGlobalScope(StoreScope::class)->create(['store_id' => $otherStore->id]);
        $foreign = $this->media($otherConfig, 'about', 'foreign.jpg');

        $this->asAdmin()->postJson("/api/v1/site-config/media-library/{$foreign->id}/use/blog")
            ->assertNotFound();
        $this->asAdmin()->patchJson("/api/v1/site-config/media-library/{$foreign->id}", [
            'original_name' => 'renamed.jpg',
            'alt_text' => 'Renamed',
        ])->assertNotFound();

        $this->assertSame('foreign.jpg', $foreign->fresh()->original_name);
    }

    public function test_library_enforces_slot_file_compatibility(): void
    {
        $video = $this->media($this->config, 'hero', 'intro.mp4', 'video/mp4');

        $this->asAdmin()->getJson('/api/v1/site-config/media-library?slot=about')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->asAdmin()->getJson('/api/v1/site-config/media-library?slot=hero&type=video')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $video->id);
        $this->asAdmin()->postJson("/api/v1/site-config/media-library/{$video->id}/use/about")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media_id');
    }

    public function test_metadata_can_be_updated_for_a_tenant_asset(): void
    {
        $asset = $this->media($this->config, 'website_editor_asset_about', 'old.jpg');

        $this->asAdmin()->patchJson("/api/v1/site-config/media-library/{$asset->id}", [
            'original_name' => 'campaign cover.jpg',
            'alt_text' => 'Two people preparing an order',
        ])->assertOk()
            ->assertJsonPath('data.name', 'campaign cover.jpg')
            ->assertJsonPath('data.alt_text', 'Two people preparing an order');

        $this->assertDatabaseHas('media', [
            'id' => $asset->id,
            'original_name' => 'campaign cover.jpg',
            'alt_text' => 'Two people preparing an order',
        ]);
    }

    public function test_deletion_is_blocked_while_history_references_an_asset(): void
    {
        $asset = $this->media($this->config, 'website_editor_asset_about', 'historic.jpg');
        $payload = HomepageSnapshot::fromConfig($this->config);
        $payload['about_media_id'] = $asset->id;
        $version = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
            'payload' => $payload,
            'published_by' => $this->admin->id,
        ]);

        $this->asAdmin()->deleteJson("/api/v1/site-config/media-library/{$asset->id}")
            ->assertConflict()
            ->assertJsonPath('usage.0', '1 published version');
        $this->assertDatabaseHas('media', ['id' => $asset->id]);
        Storage::disk('public')->assertExists($asset->path);

        $version->delete();
        $this->asAdmin()->deleteJson("/api/v1/site-config/media-library/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Media asset deleted.');
        $this->assertDatabaseMissing('media', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing($asset->path);
    }

    public function test_non_archived_media_cannot_be_deleted_directly(): void
    {
        $asset = $this->media($this->config, 'website_editor_draft_about', 'draft.jpg');

        $this->asAdmin()->deleteJson("/api/v1/site-config/media-library/{$asset->id}")
            ->assertStatus(409);
        $this->assertDatabaseHas('media', ['id' => $asset->id]);
    }
}
