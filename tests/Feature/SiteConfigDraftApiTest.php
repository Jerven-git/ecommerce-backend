<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SubscriptionMiddleware;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        ]);
        $this->updateWebsiteDocument($this->config, ['hero_title' => 'Published hero']);
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
            'site_name' => 'My Store',
            'contact_email' => '',
            'contact_phone' => '',
            'contact_entries' => [],
            'social_links' => [],
            'logo_media_id' => null,
            'logo_url' => null,
            'favicon_media_id' => null,
            'favicon_url' => null,
            'cart_icon_media_id' => null,
            'cart_icon_url' => null,
            'footer_logo_media_id' => null,
            'footer_logo_url' => null,
            'logo_alt_text' => '',
            'logo_size' => 36,
            'footer_logo_size' => 128,
            'hero_media_id' => null,
            'hero_image_url' => null,
            'hero_media_mime' => null,
            'homepage_statement_media_id' => null,
            'homepage_statement_image_url' => null,
            'about_media_id' => null,
            'about_image_url' => null,
            'story_image_a_media_id' => null,
            'story_image_a_url' => null,
            'story_image_b_media_id' => null,
            'story_image_b_url' => null,
            'contact_media_id' => null,
            'contact_image_url' => null,
            'blog_media_id' => null,
            'blog_image_url' => null,
            'services_media_id' => null,
            'services_image_url' => null,
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

        $this->assertSame('Published hero', $this->websiteDocument($this->config)['hero_title']);
        $this->assertDatabaseHas('site_config_drafts', ['store_id' => $this->store->id]);
    }

    public function test_admin_can_publish_and_discard_a_draft(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('Published from draft'),
        ])->assertOk();

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish', [
            'release_note' => 'Updated the homepage message.',
        ])
            ->assertOk()
            ->assertJsonPath('data.has_draft', false);

        $this->assertSame('Published from draft', $this->websiteDocument($this->config)['hero_title']);
        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
        $this->assertDatabaseCount('site_config_versions', 1);
        $version = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->firstOrFail();
        $this->assertSame('Updated the homepage message.', $version->release_note);
        $this->assertContains('Home hero', $version->changed_areas);

        $this->asAdmin()->getJson('/api/v1/site-config/versions')
            ->assertOk()
            ->assertJsonPath('data.0.publisher_name', $this->admin->name)
            ->assertJsonPath('data.0.release_note', 'Updated the homepage message.');

        $newBase = $this->config->fresh()->updated_at->toJSON();
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $newBase,
            'payload' => $this->payload('Discard me'),
        ])->assertOk();
        $this->asAdmin()->deleteJson('/api/v1/site-config/draft')->assertOk();
        $this->assertDatabaseMissing('site_config_drafts', ['store_id' => $this->store->id]);
    }

    public function test_publish_batches_website_media_queries(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('Query-budget draft'),
        ])->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $mediaQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "media"'),
        );
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            4,
            $mediaQueries->count(),
            $mediaQueries->pluck('query')->implode("\n"),
        );
    }

    public function test_publish_rejects_a_stale_draft(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $this->payload('Stale draft'),
        ])->assertOk();

        $this->updateWebsiteDocument($this->config, ['hero_title' => 'Changed elsewhere']);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')
            ->assertStatus(409)
            ->assertJsonPath('code', 'draft_conflict');

        $this->assertSame('Changed elsewhere', $this->websiteDocument($this->config)['hero_title']);
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

        $sections = $this->websiteDocument($this->config)['homepage_sections'];
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
        $restore = $this->asAdmin()->postJson("/api/v1/site-config/versions/{$versionId}/restore", [
            'base_hash' => $publishedHash,
        ])->assertOk()
            ->assertJsonPath('data.has_draft', true)
            ->assertJsonPath('data.payload.hero_title', 'Published hero');

        $this->assertSame('New published homepage', $this->websiteDocument($this->config)['hero_title']);
        $this->assertSame(1, SiteConfigVersion::withoutGlobalScope(StoreScope::class)->count());
        $this->assertSame(1, $restore->json('data.draft_version'));
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

        $this->assertSame('', $this->websiteDocument($this->config)['about_content']);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $published = $this->websiteDocument($this->config);
        $this->assertSame('A private About page draft.', $published['about_content']);
        $this->assertSame('Book now', $published['header_cta']['label']);
        $this->assertSame('Shop the collection', $published['shop_header']['heading']);
        $this->assertSame('Store journal', $published['blog_page']['header']['heading']);
        $this->assertSame('A private welcome offer', $published['welcome_popup_heading']);
    }

    public function test_global_store_details_publish_and_restore_with_the_website_snapshot(): void
    {
        $details = [
            'site_name' => 'Original Store',
            'contact_email' => 'hello@original.test',
            'contact_phone' => '+1 555 1000',
            'contact_entries' => [['label' => 'Sales', 'email' => 'sales@original.test', 'phone' => '']],
            'social_links' => [['platform' => 'instagram', 'url' => 'https://instagram.com/original', 'label' => 'Original Instagram']],
        ];
        $this->config->update([
            'contact_email' => $details['contact_email'],
            'contact_entries' => $details['contact_entries'],
        ]);
        $this->updateWebsiteDocument($this->config, $details);

        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $payload = $this->payload('Website draft');
        $payload['site_name'] = 'Published Store';
        $payload['contact_email'] = '';
        $payload['contact_phone'] = '';
        $payload['contact_entries'] = [['label' => 'Support', 'email' => 'support@published.test', 'phone' => '+1 555 2000']];
        $payload['social_links'] = [['platform' => 'facebook', 'url' => 'https://facebook.com/published', 'label' => 'Published Facebook']];

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertOk();

        $private = $this->websiteDocument($this->config);
        $this->assertSame('Original Store', $private['site_name']);
        $this->assertSame('hello@original.test', $private['contact_email']);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $published = $this->websiteDocument($this->config);
        $this->assertSame('Published Store', $published['site_name']);
        $this->assertSame('', $published['contact_email']);
        $this->assertSame('support@published.test', $published['contact_entries'][0]['email']);
        $this->assertSame('facebook', $published['social_links'][0]['platform']);

        $history = $this->asAdmin()->getJson('/api/v1/site-config/versions')->assertOk();
        $versionId = $history->json('data.0.id');
        $this->asAdmin()->postJson("/api/v1/site-config/versions/{$versionId}/restore", [
            'base_hash' => $history->json('published_hash'),
        ])->assertOk();

        $stillPublished = $this->websiteDocument($this->config);
        $this->assertSame('Published Store', $stillPublished['site_name']);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $restored = $this->websiteDocument($this->config);
        $this->assertSame('Original Store', $restored['site_name']);
        $this->assertSame('hello@original.test', $restored['contact_email']);
        $this->assertSame('sales@original.test', $restored['contact_entries'][0]['email']);
        $this->assertSame('instagram', $restored['social_links'][0]['platform']);
    }

    public function test_a_draft_created_before_global_details_still_publishes_safely(): void
    {
        $published = HomepageSnapshot::fromConfig($this->config->fresh());
        $legacyPayload = collect($published)->except([
            'site_name', 'contact_email', 'contact_phone', 'contact_entries', 'social_links',
        ])->all();
        $legacyPayload['hero_title'] = 'Legacy draft hero';

        SiteConfigDraft::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
            'payload' => $legacyPayload,
            'base_updated_at' => $this->config->updated_at,
            'base_hash' => HomepageSnapshot::hashBeforeGlobalDetails($published),
            'lock_version' => 1,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $fresh = $this->websiteDocument($this->config);
        $this->assertSame('Legacy draft hero', $fresh['hero_title']);
        $this->assertSame('My Store', $fresh['site_name']);
    }

    public function test_brand_assets_remain_private_until_publish_and_restore_with_versions(): void
    {
        Storage::fake('public');
        Queue::fake();

        $original = $this->asAdmin()->post('/api/v1/site-config/media/logo', [
            'file' => UploadedFile::fake()->image('original-logo.png', 240, 80),
        ])->assertOk();
        $originalId = $original->json('id');

        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $draftAsset = $this->asAdmin()->post('/api/v1/site-config/draft/media/logo', [
            'file' => UploadedFile::fake()->image('draft-logo.png', 300, 100),
        ])->assertOk();
        $draftAssetId = $draftAsset->json('id');

        $this->assertSame($originalId, $this->config->fresh()->logoMedia?->id);
        $this->assertSame('website_editor_draft_logo', Media::findOrFail($draftAssetId)->collection);

        $payload = $this->payload('Website draft');
        $payload['logo_media_id'] = $draftAssetId;
        $payload['logo_url'] = 'https://untrusted.example/ignored.png';
        $payload['logo_alt_text'] = 'Draft logo';
        $payload['logo_size'] = 52;

        $saved = $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertOk();

        $this->assertSame($draftAsset->json('url'), $saved->json('data.payload.logo_url'));
        $this->assertSame($originalId, $this->config->fresh()->logoMedia?->id);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $published = $this->config->fresh();
        $publishedDocument = $this->websiteDocument($published);
        $this->assertSame($draftAssetId, $published->logoMedia?->id);
        $this->assertSame('Draft logo', $publishedDocument['logo_alt_text']);
        $this->assertSame(52, $publishedDocument['logo_size']);
        $this->assertSame('website_editor_asset_logo', Media::findOrFail($originalId)->collection);

        $history = $this->asAdmin()->getJson('/api/v1/site-config/versions')->assertOk();
        $versionId = $history->json('data.0.id');
        $this->asAdmin()->postJson("/api/v1/site-config/versions/{$versionId}/restore", [
            'base_hash' => $history->json('published_hash'),
        ])->assertOk();

        $this->assertSame($draftAssetId, $this->config->fresh()->logoMedia?->id);
        $this->assertSame($originalId, SiteConfigDraft::withoutGlobalScope(StoreScope::class)->where('store_id', $this->store->id)->firstOrFail()->payload['logo_media_id']);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();
        $this->assertSame($originalId, $this->config->fresh()->logoMedia?->id);
        $this->assertSame('website_editor_asset_logo', Media::findOrFail($draftAssetId)->collection);
    }

    public function test_page_media_remains_private_and_restores_with_the_published_version(): void
    {
        Storage::fake('public');
        Queue::fake();

        $original = $this->asAdmin()->post('/api/v1/site-config/media/about', [
            'file' => UploadedFile::fake()->image('original-about.jpg', 1200, 800),
        ])->assertOk();
        $draftAsset = $this->asAdmin()->post('/api/v1/site-config/draft/media/about', [
            'file' => UploadedFile::fake()->image('draft-about.jpg', 1200, 800),
        ])->assertOk();
        $draftHero = $this->asAdmin()->post('/api/v1/site-config/draft/media/hero', [
            'file' => UploadedFile::fake()->image('draft-hero.png', 1600, 900),
        ])->assertOk();

        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $payload = $this->payload('Website draft');
        $payload['about_media_id'] = $draftAsset->json('id');
        $payload['about_image_url'] = 'https://untrusted.example/ignored.jpg';
        $payload['hero_media_id'] = $draftHero->json('id');
        $payload['hero_image_url'] = 'https://untrusted.example/ignored.png';
        $payload['hero_media_mime'] = 'video/mp4';

        $saved = $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertOk();

        $this->assertSame($draftAsset->json('url'), $saved->json('data.payload.about_image_url'));
        $this->assertSame($draftHero->json('url'), $saved->json('data.payload.hero_image_url'));
        $this->assertSame('image/png', $saved->json('data.payload.hero_media_mime'));
        $this->assertSame($original->json('id'), $this->config->fresh()->aboutMedia?->id);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();
        $this->assertSame($draftAsset->json('id'), $this->config->fresh()->aboutMedia?->id);

        $history = $this->asAdmin()->getJson('/api/v1/site-config/versions')->assertOk();
        $this->asAdmin()->postJson('/api/v1/site-config/versions/'.$history->json('data.0.id').'/restore', [
            'base_hash' => $history->json('published_hash'),
        ])->assertOk();

        $this->assertSame($draftAsset->json('id'), $this->config->fresh()->aboutMedia?->id);
        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $restored = $this->config->fresh();
        $this->assertSame($original->json('id'), $restored->aboutMedia?->id);
        $this->assertSame($original->json('url'), $this->websiteDocument($restored)['about_image_url']);
    }

    public function test_a_draft_created_before_page_media_fields_still_publishes(): void
    {
        $published = HomepageSnapshot::fromConfig($this->config->fresh());
        $legacyPayload = collect($published)->except([
            'hero_media_id', 'hero_image_url', 'hero_media_mime',
            'homepage_statement_media_id', 'homepage_statement_image_url',
            'about_media_id', 'about_image_url',
            'story_image_a_media_id', 'story_image_a_url',
            'story_image_b_media_id', 'story_image_b_url',
            'contact_media_id', 'contact_image_url',
            'blog_media_id', 'blog_image_url',
            'services_media_id', 'services_image_url',
        ])->all();
        $legacyPayload['hero_title'] = 'Pre-media draft';

        SiteConfigDraft::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->store->id,
            'payload' => $legacyPayload,
            'base_updated_at' => $this->config->updated_at,
            'base_hash' => HomepageSnapshot::hashBeforePageMedia($published),
            'lock_version' => 1,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();
        $this->assertSame('Pre-media draft', $this->websiteDocument($this->config)['hero_title']);
    }

    public function test_brand_asset_ids_cannot_cross_store_boundaries(): void
    {
        Storage::fake('public');
        Queue::fake();

        $otherStore = Store::factory()->create();
        $otherConfig = SiteConfig::withoutGlobalScope(StoreScope::class)->create(['store_id' => $otherStore->id]);
        $foreign = $otherConfig->media()->create([
            'collection' => 'logo',
            'hash' => 'foreign-logo',
            'path' => 'site-config/foreign-logo.png',
            'format' => 'png',
            'mime_type' => 'image/png',
            'size' => 100,
        ]);

        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->json('data.base_updated_at');
        $payload = $this->payload('Website draft');
        $payload['logo_media_id'] = $foreign->id;
        $payload['logo_url'] = $foreign->url;

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.logo_media_id');
    }

    public function test_discarding_a_draft_removes_unpublished_brand_assets(): void
    {
        Storage::fake('public');
        Queue::fake();

        $asset = $this->asAdmin()->post('/api/v1/site-config/draft/media/favicon', [
            'file' => UploadedFile::fake()->image('draft-icon.png', 64, 64),
        ])->assertOk();
        $assetId = $asset->json('id');
        $path = Media::findOrFail($assetId)->path;

        $this->asAdmin()->deleteJson('/api/v1/site-config/draft')->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $assetId]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_drafts_are_isolated_by_store(): void
    {
        $otherStore = Store::factory()->create();
        $otherConfig = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $otherStore->id,
        ]);
        $this->updateWebsiteDocument($otherConfig, ['hero_title' => 'Other published hero']);
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
