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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteConfigDraftApiTest extends TestCase
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
        $role = Role::firstOrCreate(['name' => 'admin']);
        $this->admin->roles()->attach($role);
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
    private function payload(string $heroTitle): array
    {
        return [
            'hero_title' => $heroTitle,
            'hero_subtitle' => 'Draft subtitle',
            'hero_overlay_color' => '#000000',
            'hero_overlay_opacity' => 45,
            'hero_full_bleed' => false,
            'hero_focal_x' => 50,
            'hero_focal_y' => 50,
            'homepage_steps' => ['label' => '', 'heading' => '', 'subtitle' => '', 'items' => []],
            'homepage_features' => ['items' => []],
            'homepage_stats' => ['items' => []],
            'homepage_statement' => ['enabled' => false, 'eyebrow' => '', 'quote' => '', 'attribution' => '', 'role' => '', 'cta_label' => '', 'cta_link' => ''],
            'homepage_newsletter' => ['label' => '', 'heading' => '', 'subtitle' => '', 'disclaimer' => ''],
            'homepage_sections' => [
                ['id' => 'hero', 'visible' => true],
                ['id' => 'featured-products', 'visible' => true],
                ['id' => 'showcase', 'visible' => true],
                ['id' => 'watch-shop', 'visible' => true],
                ['id' => 'best-sellers', 'visible' => true],
                ['id' => 'how-it-works', 'visible' => true],
                ['id' => 'features', 'visible' => true],
                ['id' => 'stats', 'visible' => true],
                ['id' => 'statement', 'visible' => true],
                ['id' => 'newsletter', 'visible' => true],
            ],
            'header_cta' => ['enabled' => false, 'label' => '', 'link' => ''],
            'footer_banner' => [
                'enabled' => false,
                'heading' => '',
                'subtitle' => '',
                'button_label' => '',
                'button_link' => '',
                'background_color' => '#111827',
                'background_color_to' => null,
                'text_color' => '#ffffff',
            ],
            'footer' => [
                'tagline' => '',
                'copyright_text' => '',
                'show_tagline' => true,
                'show_quick_links' => true,
                'show_contact_info' => true,
                'show_social_links' => true,
            ],
            'about_content' => '',
            'about_highlights' => ['items' => []],
            'story_page' => [
                'enabled' => false,
                'hero' => ['eyebrow' => '', 'heading' => '', 'subtitle' => ''],
                'section_a' => ['heading' => '', 'body' => '', 'cta_label' => '', 'cta_link' => '/shop', 'image_position' => 'right', 'image_url' => null],
                'section_b' => ['heading' => '', 'body' => '', 'cta_label' => '', 'cta_link' => '/contact', 'image_position' => 'left', 'image_url' => null],
            ],
            'contact_page' => [
                'info_title' => '', 'info_subtitle' => '', 'info_description' => '', 'info_badge' => '',
                'promises' => [], 'faq_label' => '', 'faq_heading' => '', 'faq_subtitle' => '', 'faqs' => [],
            ],
            'shop_header' => ['label' => '', 'heading' => '', 'subtitle' => ''],
            'shop_promo' => ['badge' => '', 'heading' => '', 'subtitle' => '', 'button_text' => '', 'perks' => []],
            'blog_page' => ['header' => ['label' => '', 'heading' => '', 'subtitle' => ''], 'cta' => null],
            'services_page' => [
                'header' => ['label' => '', 'heading' => '', 'subtitle' => '', 'primary_cta' => null, 'secondary_cta' => null],
                'summary' => null,
                'stats' => null,
                'cta' => null,
            ],
            'welcome_popup_enabled' => false,
            'welcome_popup_heading' => '',
            'welcome_popup_body' => '',
        ];
    }

    public function test_saving_a_draft_does_not_change_the_published_config(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')
            ->assertOk()
            ->json('data.base_updated_at');

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('Private draft hero'),
        ])->assertOk()->assertJsonPath('data.has_draft', true);

        $this->assertSame('Published hero', $this->config->fresh()->hero_title);
        $this->assertDatabaseHas('site_config_drafts', ['store_id' => $this->store->id]);
    }

    public function test_admin_can_publish_and_discard_a_draft(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('Published from draft'),
        ])->assertOk();

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')
            ->assertOk()
            ->assertJsonPath('data.has_draft', false);

        $this->assertSame('Published from draft', $this->config->fresh()->hero_title);
        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
        $this->assertDatabaseCount('site_config_versions', 1);

        $newBase = $this->config->fresh()->updated_at->toJSON();
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $newBase,
            'payload' => $this->payload('Discard me'),
        ])->assertOk();
        $this->asAdmin()->deleteJson('/api/v1/site-config/draft')->assertOk();
        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
    }

    public function test_publish_rejects_a_stale_draft(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('Stale draft'),
        ])->assertOk();

        $this->config->update(['hero_title' => 'Changed elsewhere']);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')
            ->assertStatus(409)
            ->assertJsonPath('code', 'draft_conflict');

        $this->assertSame('Changed elsewhere', $this->config->fresh()->hero_title);
        $this->assertDatabaseHas('site_config_drafts', ['store_id' => $this->store->id]);
    }

    public function test_saving_rejects_an_outdated_private_draft_version(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $firstSave = $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'draft_version' => null,
            'payload' => $this->payload('First private version'),
        ])->assertOk();

        $version = $firstSave->json('data.draft_version');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'draft_version' => $version,
            'payload' => $this->payload('Second private version'),
        ])->assertOk()->assertJsonPath('data.draft_version', $version + 1);

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'draft_version' => $version,
            'payload' => $this->payload('Overwrite from stale editor'),
        ])->assertStatus(409)->assertJsonPath('code', 'draft_save_conflict');

        $this->assertSame('Second private version', SiteConfigDraft::firstOrFail()->payload['hero_title']);
    }

    public function test_section_composition_publishes_in_the_saved_order(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $payload = $this->payload('Composed homepage');
        $payload['homepage_sections'] = array_reverse($payload['homepage_sections']);
        $payload['homepage_sections'][0]['visible'] = false;

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $sections = $this->config->fresh()->homepage_sections;
        $this->assertSame('newsletter', $sections[0]['id']);
        $this->assertFalse($sections[0]['visible']);
    }

    public function test_admin_can_preview_and_restore_a_published_version(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('New published homepage'),
        ])->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $history = $this->asAdmin()->getJson('/api/v1/site-config/versions')->assertOk();
        $versionId = $history->json('data.0.id');
        $publishedHash = $history->json('published_hash');

        $this->asAdmin()->getJson("/api/v1/site-config/versions/{$versionId}")
            ->assertOk()
            ->assertJsonPath('data.payload.hero_title', 'Published hero');
        $this->asAdmin()->postJson("/api/v1/site-config/versions/{$versionId}/restore", [
            'base_hash' => $publishedHash,
        ])->assertOk();

        $this->assertSame('Published hero', $this->config->fresh()->hero_title);
        $this->assertSame(2, SiteConfigVersion::withoutGlobalScope(StoreScope::class)->count());
    }

    public function test_additional_storefront_pages_publish_through_the_same_draft(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $payload = $this->payload('Website draft');
        $payload['header_cta'] = ['enabled' => true, 'label' => 'Book now', 'link' => '/contact'];
        $payload['about_content'] = 'A private About page draft.';
        $payload['shop_header'] = ['label' => 'Catalogue', 'heading' => 'Shop the collection', 'subtitle' => 'Made for this store.'];
        $payload['blog_page']['header']['heading'] = 'Store journal';
        $payload['welcome_popup_heading'] = 'A private welcome offer';

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertOk();

        $this->assertNull($this->config->fresh()->about_content);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $published = $this->config->fresh();
        $this->assertSame('A private About page draft.', $published->about_content);
        $this->assertSame('Book now', $published->header_cta['label']);
        $this->assertSame('Shop the collection', $published->shop_header['heading']);
        $this->assertSame('Store journal', $published->blog_page['header']['heading']);
        $this->assertSame('A private welcome offer', $published->welcome_popup_heading);
    }

    public function test_drafts_are_isolated_by_store(): void
    {
        $otherStore = Store::factory()->create();
        SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $otherStore->id,
            'hero_title' => 'Other published hero',
        ]);
        $otherAdmin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $otherStore->id,
        ]);
        $otherAdmin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('First store draft'),
        ])->assertOk();

        $this->asAdmin($otherAdmin)->getJson('/api/v1/site-config/draft')
            ->assertOk()
            ->assertJsonPath('data.payload.hero_title', 'Other published hero')
            ->assertJsonPath('data.has_draft', false);

        $this->assertSame(1, SiteConfigDraft::withoutGlobalScope(StoreScope::class)->count());
    }
}
