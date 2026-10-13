<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SubscriptionMiddleware;
use App\Models\Activity;
use App\Models\Media;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\SiteConfigVersion;
use App\Models\Store;
use App\Models\User;
use App\Support\WebsiteEditor\HomepageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class WebsiteEditorHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private SiteConfig $config;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
            'hero_title' => 'Published hero',
        ]);
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $this->store->id,
        ]);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->admin->roles()->attach($adminRole);
    }

    private function asAdmin(?User $user = null): self
    {
        return $this->actingAs($user ?? $this->admin)
            ->withoutMiddleware([
                SessionLifetimeMiddleware::class,
                SubscriptionMiddleware::class,
            ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $heroTitle = 'Private draft'): array
    {
        $payload = HomepageSnapshot::fromConfig($this->config->fresh());
        $payload['hero_title'] = $heroTitle;

        return $payload;
    }

    private function saveDraft(array $payload): SiteConfigDraft
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')
            ->assertOk()
            ->json('data.base_updated_at');

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertOk();

        return SiteConfigDraft::withoutGlobalScope(StoreScope::class)->firstOrFail();
    }

    public function test_editor_routes_require_authentication_and_admin_access(): void
    {
        $this->getJson('/api/v1/site-config/draft')->assertUnauthorized();

        $customer = User::factory()->create([
            'is_admin' => false,
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($customer)
            ->withoutMiddleware([SessionLifetimeMiddleware::class, SubscriptionMiddleware::class])
            ->getJson('/api/v1/site-config/draft')
            ->assertForbidden();
    }

    public function test_other_store_versions_fail_closed(): void
    {
        $otherStore = Store::factory()->create();
        $otherConfig = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $otherStore->id,
            'hero_title' => 'Other tenant',
        ]);
        $otherVersion = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $otherStore->id,
            'payload' => HomepageSnapshot::fromConfig($otherConfig),
            'published_by' => $this->admin->id,
        ]);

        $this->asAdmin()->getJson("/api/v1/site-config/versions/{$otherVersion->id}")
            ->assertNotFound();
        $this->asAdmin()->postJson("/api/v1/site-config/versions/{$otherVersion->id}/restore", [
            'base_hash' => HomepageSnapshot::hash($this->payload('Published hero')),
        ])->assertNotFound();

        $this->assertSame('Published hero', $this->config->fresh()->hero_title);
        $this->assertSame('Other tenant', $otherConfig->fresh()->hero_title);
    }

    public function test_unsafe_storefront_links_are_rejected_server_side(): void
    {
        $payload = $this->payload();
        $payload['header_cta'] = [
            'enabled' => true,
            'label' => 'Unsafe',
            'link' => 'javascript:alert(1)',
        ];
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payload.header_cta.link']);

        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
    }

    public function test_publish_is_atomic_when_the_config_update_fails(): void
    {
        $draft = $this->saveDraft($this->payload('Must not publish'));

        SiteConfig::updating(function (): void {
            throw new RuntimeException('Simulated persistence failure.');
        });

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertServerError();

        $this->assertSame('Published hero', $this->config->fresh()->hero_title);
        $this->assertDatabaseHas('site_config_drafts', ['id' => $draft->id]);
        $this->assertDatabaseCount('site_config_versions', 0);
        $this->assertDatabaseMissing('activity_log', ['event' => 'website_published']);
    }

    public function test_draft_save_rolls_back_if_its_audit_record_cannot_be_written(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        Activity::creating(function (): void {
            throw new RuntimeException('Simulated audit storage failure.');
        });

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('Must roll back'),
        ])->assertServerError();

        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
        $this->assertSame('Published hero', $this->config->fresh()->hero_title);
    }

    public function test_draft_publish_and_discard_actions_are_audited_without_content(): void
    {
        $this->saveDraft($this->payload('Audited publication'));
        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $this->saveDraft($this->payload('Discarded draft'));
        $this->asAdmin()->deleteJson('/api/v1/site-config/draft')->assertOk();

        $events = Activity::query()
            ->where('log_name', 'website_editor')
            ->orderBy('id')
            ->get();

        $this->assertSame([
            'draft_saved',
            'website_published',
            'draft_saved',
            'draft_discarded',
        ], $events->pluck('event')->all());
        $this->assertTrue($events->every(fn (Activity $activity) => $activity->store_id === $this->store->id));
        $this->assertTrue($events->every(fn (Activity $activity) => $activity->causer_id === $this->admin->id));
        $this->assertStringNotContainsString('Audited publication', $events->toJson());
        $this->assertStringNotContainsString('Discarded draft', $events->toJson());
    }

    public function test_publish_conflicts_emit_structured_monitoring_context(): void
    {
        Log::spy();
        $draft = $this->saveDraft($this->payload('Stale publication'));
        $this->config->update(['hero_title' => 'Changed elsewhere']);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')
            ->assertConflict()
            ->assertJsonPath('code', 'draft_conflict');

        Log::shouldHaveReceived('warning')
            ->with('Website editor operation rejected', \Mockery::on(fn (array $context) => $context['operation'] === 'publish'
                && $context['reason'] === 'draft_conflict'
                && $context['store_id'] === $this->store->id
                && $context['draft_id'] === $draft->id))
            ->once();
    }

    public function test_version_restore_is_audited_and_clears_the_private_draft(): void
    {
        $historicalPayload = $this->payload('Historical hero');
        $version = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
            'payload' => $historicalPayload,
            'published_by' => $this->admin->id,
        ]);
        $this->saveDraft($this->payload('Draft replaced by restore'));
        $currentHash = HomepageSnapshot::hash(HomepageSnapshot::fromConfig($this->config->fresh()));

        $this->asAdmin()->postJson("/api/v1/site-config/versions/{$version->id}/restore", [
            'base_hash' => $currentHash,
        ])->assertOk();

        $this->assertSame('Historical hero', $this->config->fresh()->hero_title);
        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
        $this->assertDatabaseHas('activity_log', [
            'store_id' => $this->store->id,
            'causer_id' => $this->admin->id,
            'event' => 'version_restored',
        ]);
    }

    public function test_version_restore_conflict_preserves_current_site_and_history(): void
    {
        $version = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
            'payload' => $this->payload('Historical hero'),
            'published_by' => $this->admin->id,
        ]);
        $staleHash = HomepageSnapshot::hash(HomepageSnapshot::fromConfig($this->config->fresh()));
        $this->config->update(['hero_title' => 'Newer publication']);

        $this->asAdmin()->postJson("/api/v1/site-config/versions/{$version->id}/restore", [
            'base_hash' => $staleHash,
        ])->assertConflict()->assertJsonPath('code', 'version_restore_conflict');

        $this->assertSame('Newer publication', $this->config->fresh()->hero_title);
        $this->assertDatabaseCount('site_config_versions', 1);
        $this->assertDatabaseMissing('activity_log', ['event' => 'version_restored']);
    }

    public function test_monitor_reports_stale_drafts_and_stalled_media(): void
    {
        Log::spy();
        $draft = $this->saveDraft($this->payload());
        $media = $this->config->media()->create([
            'hash' => str_repeat('a', 32),
            'path' => 'site-config/stalled.jpg',
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'collection' => 'hero',
            'processing_status' => 'pending',
        ]);
        $staleAt = now()->subDays(2);
        SiteConfigDraft::withoutGlobalScope(StoreScope::class)->whereKey($draft->id)->update(['updated_at' => $staleAt]);
        Media::query()->whereKey($media->id)->update(['updated_at' => $staleAt]);

        $exitCode = Artisan::call('website-editor:monitor', [
            '--draft-minutes' => 60,
            '--media-minutes' => 60,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 stale draft(s), 1 stalled media item(s)', Artisan::output());
        Log::shouldHaveReceived('warning')->with(
            'Website editor stale drafts detected',
            \Mockery::on(fn (array $context) => $context['count'] === 1),
        )->once();
        Log::shouldHaveReceived('warning')->with(
            'Website editor stalled media processing detected',
            \Mockery::on(fn (array $context) => $context['count'] === 1),
        )->once();
    }
}
