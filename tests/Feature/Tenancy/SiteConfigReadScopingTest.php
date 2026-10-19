<?php

namespace Tests\Feature\Tenancy;

use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteConfigReadScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $defaultStore;

    protected Store $newStore;

    protected User $newAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $defaultConfig = SiteConfig::withoutGlobalScope(StoreScope::class)->updateOrCreate(
            ['store_id' => $this->defaultStore->id],
            [],
        );
        $this->updateWebsiteDocument($defaultConfig, [
            'site_name' => 'Default Storefront',
            'hero_title' => 'Default Hero',
        ]);

        $this->newStore = Store::factory()->create(['name' => 'Watch World']);
        $newConfig = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->newStore->id,
        ]);
        $this->updateWebsiteDocument($newConfig, [
            'site_name' => 'Watch World',
            'hero_title' => 'Watch World Hero',
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->newAdmin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $this->newStore->id,
        ]);
        $this->newAdmin->roles()->attach($adminRole);

        app(CurrentStore::class)->clear();
    }

    public function test_unauthenticated_public_request_returns_default_store_config(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Default Hero');
    }

    public function test_public_site_config_read_stays_within_its_query_budget(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/site-config')->assertOk();

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $sql = $queries->pluck('query')->implode("\n");

        $this->assertLessThanOrEqual(5, $queries->count(), $sql);
        $this->assertCount(1, $queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "media"'),
        ), $sql);
        $this->assertCount(1, $queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "site_config_documents"'),
        ), $sql);
        $this->assertCount(0, $queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "order_items"'),
        ), $sql);
    }

    public function test_authenticated_admin_sees_their_own_store_config_via_public_endpoint(): void
    {
        $this->actingAs($this->newAdmin)
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Watch World Hero');
    }

    public function test_public_site_config_top_level_contract_is_stable(): void
    {
        $response = $this->getJson('/api/v1/site-config')->assertOk();
        $keys = array_keys($response->json('data'));
        sort($keys);

        $expected = $this->siteConfigResponseKeys();
        sort($expected);

        $this->assertSame($expected, $keys);
        $this->assertArrayNotHasKey('localized_content', $response->json('data'));
    }

    public function test_authenticated_site_config_adds_only_private_localized_content(): void
    {
        $response = $this->actingAs($this->newAdmin)
            ->getJson('/api/v1/site-config')
            ->assertOk();
        $keys = array_keys($response->json('data'));
        sort($keys);

        $expected = [...$this->siteConfigResponseKeys(), 'localized_content'];
        sort($expected);

        $this->assertSame($expected, $keys);
        $this->assertIsArray($response->json('data.localized_content'));
    }

    public function test_unauthenticated_request_with_store_slug_param_returns_that_store_config(): void
    {
        $this->getJson('/api/v1/site-config?store='.$this->newStore->slug)
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Watch World Hero');
    }

    public function test_unauthenticated_request_with_unknown_store_slug_falls_back_to_default(): void
    {
        $this->getJson('/api/v1/site-config?store=does-not-exist')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Default Hero');
    }

    public function test_unauthenticated_request_with_inactive_store_slug_falls_back_to_default(): void
    {
        $this->newStore->update(['status' => 'inactive']);

        $this->getJson('/api/v1/site-config?store='.$this->newStore->slug)
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Default Hero');
    }

    public function test_admin_theme_update_round_trips_through_public_read(): void
    {
        $this->actingAs($this->newAdmin)
            ->patchJson('/api/v1/site-config', [
                'hero_title' => 'Brand New Hero',
            ])
            ->assertOk();

        $this->actingAs($this->newAdmin)
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Brand New Hero');

        // Default store must be untouched.
        $defaultConfig = SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $this->defaultStore->id)
            ->firstOrFail();
        $this->assertSame('Default Hero', $this->websiteDocument($defaultConfig)['hero_title']);
    }

    /** @return array<int, string> */
    private function siteConfigResponseKeys(): array
    {
        return [
            'id',
            'is_storefront_host',
            'canonical_host',
            'site_name',
            'default_locale',
            'supported_locales',
            'locale',
            'theme',
            'hero_title',
            'hero_subtitle',
            'hero_overlay_color',
            'hero_overlay_opacity',
            'hero_full_bleed',
            'hero_focal_x',
            'hero_focal_y',
            'about_content',
            'story_page',
            'about_overlay_color',
            'about_overlay_opacity',
            'contact_overlay_color',
            'contact_overlay_opacity',
            'badge_in_stock_color',
            'contact_email',
            'contact_phone',
            'contact_entries',
            'social_links',
            'favorites_enabled',
            'show_stock_quantity',
            'backorder_enabled',
            'welcome_popup_enabled',
            'welcome_popup_heading',
            'welcome_popup_body',
            'welcome_popup_discount_id',
            'homepage_steps',
            'homepage_features',
            'homepage_stats',
            'homepage_statement',
            'homepage_newsletter',
            'homepage_showcase',
            'homepage_watch_shop',
            'homepage_best_sellers',
            'homepage_sections',
            'about_highlights',
            'shop_header',
            'shop_promo',
            'contact_page',
            'blog_page',
            'blog_overlay_color',
            'blog_overlay_opacity',
            'services_page',
            'services_overlay_color',
            'services_overlay_opacity',
            'modules_enabled',
            'default_seo_title',
            'default_seo_description',
            'default_og_image_url',
            'pages_seo',
            'canonical_base_url',
            'logo_alt_text',
            'logo_size',
            'footer_logo_size',
            'loader_animation',
            'header_cta',
            'footer_banner',
            'footer',
            'currency_code',
            'updated_at',
            'logo_url',
            'favicon_url',
            'cart_icon_url',
            'footer_logo_url',
            'loader_logo_url',
            'hero_image_url',
            'hero_media_mime',
            'about_image_url',
            'contact_image_url',
            'blog_image_url',
            'services_image_url',
        ];
    }
}
