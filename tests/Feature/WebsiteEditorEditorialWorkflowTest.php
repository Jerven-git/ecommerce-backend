<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SubscriptionMiddleware;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\SiteConfigVersion;
use App\Models\Store;
use App\Models\User;
use App\Support\WebsiteEditor\HomepageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WebsiteEditorEditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private SiteConfig $config;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['timezone' => 'Asia/Singapore']);
        $this->config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
            'hero_title' => 'Published hero',
        ]);
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $this->store->id,
        ]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware([
                SessionLifetimeMiddleware::class,
                SubscriptionMiddleware::class,
            ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $heroTitle): array
    {
        $payload = HomepageSnapshot::fromConfig($this->config->fresh());
        $payload['hero_title'] = $heroTitle;

        return $payload;
    }

    private function saveDraft(string $heroTitle = 'Approved launch'): int
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')
            ->assertOk()
            ->json('data.base_updated_at');

        return (int) $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload($heroTitle),
        ])->assertOk()->json('data.draft_version');
    }

    public function test_admin_can_request_review_approve_and_schedule_in_the_store_timezone(): void
    {
        Carbon::setTestNow('2026-10-20 00:00:00 UTC');
        $version = $this->saveDraft();

        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/request', [
            'draft_version' => $version,
            'note' => 'Please check the launch message.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by_name', $this->admin->name);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/approve', [
            'draft_version' => $version,
            'note' => 'Ready for launch.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by_name', $this->admin->name);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/schedule', [
            'draft_version' => $version,
            'scheduled_for' => '2026-10-20T10:30',
            'release_note' => 'Scheduled homepage launch.',
        ])->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Singapore')
            ->assertJsonPath('data.scheduled_release_note', 'Scheduled homepage launch.');

        $draft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)->firstOrFail();
        $this->assertSame('2026-10-20 02:30:00', $draft->scheduled_publish_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_scheduled_command_publishes_an_approved_due_draft(): void
    {
        Carbon::setTestNow('2026-10-20 00:00:00 UTC');
        $version = $this->saveDraft('Scheduled hero');
        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/request', ['draft_version' => $version])->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/approve', ['draft_version' => $version])->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/schedule', [
            'draft_version' => $version,
            'scheduled_for' => '2026-10-20T10:30',
            'release_note' => 'Timed release.',
        ])->assertOk();

        Carbon::setTestNow('2026-10-20 02:31:00 UTC');
        $this->assertSame(0, Artisan::call('website-editor:publish-scheduled'));

        $this->assertSame('Scheduled hero', $this->config->fresh()->hero_title);
        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
        $version = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->firstOrFail();
        $this->assertSame($this->admin->id, $version->published_by);
        $this->assertSame('Timed release.', $version->release_note);
    }

    public function test_saving_after_approval_cancels_review_and_schedule(): void
    {
        Carbon::setTestNow('2026-10-20 00:00:00 UTC');
        $version = $this->saveDraft();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/request', ['draft_version' => $version])->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/approve', ['draft_version' => $version])->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/schedule', [
            'draft_version' => $version,
            'scheduled_for' => '2026-10-20T10:30',
        ])->assertOk();

        $base = SiteConfigDraft::withoutGlobalScope(StoreScope::class)->firstOrFail()->base_updated_at->toJSON();
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'draft_version' => $version,
            'payload' => $this->payload('Changed after approval'),
        ])->assertOk()
            ->assertJsonPath('data.workflow.status', 'draft')
            ->assertJsonPath('data.workflow.scheduled_publish_at', null);

        $draft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)->firstOrFail();
        $this->assertNull($draft->reviewed_by);
        $this->assertNull($draft->scheduled_by);
    }

    public function test_changes_request_requires_a_note_and_approved_status_is_required_for_scheduling(): void
    {
        Carbon::setTestNow('2026-10-20 00:00:00 UTC');
        $version = $this->saveDraft();

        $this->asAdmin()->postJson('/api/v1/site-config/draft/schedule', [
            'draft_version' => $version,
            'scheduled_for' => '2026-10-20T10:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('review_status');

        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/request', ['draft_version' => $version])->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/request-changes', ['draft_version' => $version])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/request-changes', [
            'draft_version' => $version,
            'note' => 'Clarify the main heading.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'changes_requested')
            ->assertJsonPath('data.note', 'Clarify the main heading.');
    }

    public function test_stale_editor_cannot_change_the_review_state_of_a_newer_draft(): void
    {
        $version = $this->saveDraft('First saved draft');
        $draft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)->firstOrFail();

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $draft->base_updated_at->toJSON(),
            'draft_version' => $version,
            'payload' => $this->payload('Newer saved draft'),
        ])->assertOk();

        $this->asAdmin()->postJson('/api/v1/site-config/draft/review/request', [
            'draft_version' => $version,
        ])->assertStatus(409)->assertJsonPath('code', 'draft_workflow_conflict');

        $this->assertSame('draft', SiteConfigDraft::withoutGlobalScope(StoreScope::class)->firstOrFail()->review_status);
    }
}
