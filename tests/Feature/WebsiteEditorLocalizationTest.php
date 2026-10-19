<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SubscriptionMiddleware;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Support\WebsiteEditor\HomepageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteEditorLocalizationTest extends TestCase
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
        $this->updateWebsiteDocument($this->config, [
            'site_name' => 'Default Store',
            'hero_title' => 'Default hero',
            'hero_subtitle' => 'Default supporting text',
            'homepage_steps' => [
                'label' => 'How it works',
                'heading' => 'Three steps',
                'subtitle' => 'Simple process',
                'items' => [['icon' => 'heroicons:check', 'title' => 'Choose', 'description' => 'Pick an item']],
            ],
        ]);
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'store_id' => $this->store->id,
        ]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware([
                SessionLifetimeMiddleware::class,
                SubscriptionMiddleware::class,
            ]);
    }

    public function test_site_config_applies_supported_locale_overrides_with_default_fallbacks(): void
    {
        $this->updateWebsiteDocument($this->config, [
            'default_locale' => 'en',
            'supported_locales' => ['en', 'fr'],
            'localized_content' => [
                'fr' => [
                    'site_name' => 'Boutique',
                    'hero_title' => 'Titre français',
                    'homepage_steps.items.0.title' => 'Choisir',
                ],
            ],
        ]);

        $this->asAdmin()->getJson('/api/v1/site-config?locale=fr')
            ->assertOk()
            ->assertHeader('Content-Language', 'fr')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.site_name', 'Boutique')
            ->assertJsonPath('data.hero_title', 'Titre français')
            ->assertJsonPath('data.hero_subtitle', 'Default supporting text')
            ->assertJsonPath('data.homepage_steps.items.0.title', 'Choisir')
            ->assertJsonPath('data.homepage_steps.items.0.description', 'Pick an item')
            ->assertJsonPath('data.localized_content.fr.hero_title', 'Titre français');

        $this->asAdmin()->getJson('/api/v1/site-config?locale=de')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.hero_title', 'Default hero');
    }

    public function test_locale_resolution_honors_explicit_choice_cookie_and_browser_language(): void
    {
        $this->updateWebsiteDocument($this->config, [
            'default_locale' => 'en',
            'supported_locales' => ['en', 'fr'],
            'localized_content' => ['fr' => ['hero_title' => 'Titre français']],
        ]);

        $this->withCredentials()->withUnencryptedCookie('storefront_locale', 'fr')->asAdmin()
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertHeader('Content-Language', 'fr')
            ->assertJsonPath('data.hero_title', 'Titre français');

        $this->withCredentials()->withUnencryptedCookie('storefront_locale', 'fr')->asAdmin()
            ->getJson('/api/v1/site-config?locale=default')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.hero_title', 'Default hero');

        $this->app['auth']->guard()->logout();
        $this->withUnencryptedCookie('storefront_locale', '')
            ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->getJson('/api/v1/site-config?store='.$this->store->slug)
            ->assertOk()
            ->assertHeader('Content-Language', 'fr')
            ->assertJsonPath('data.hero_title', 'Titre français')
            ->assertJsonMissingPath('data.localized_content');
    }

    public function test_localized_content_is_validated_and_published_with_the_private_draft(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->assertOk()->json('data.base_updated_at');
        $payload = HomepageSnapshot::fromConfig($this->config->fresh());
        $payload['default_locale'] = 'en';
        $payload['supported_locales'] = ['en', 'fr'];
        $payload['localized_content'] = [
            'fr' => [
                'hero_title' => 'Lancement localisé',
                'pages_seo.home.seo_title' => 'Accueil localisé',
            ],
        ];

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertOk()->assertJsonPath('data.payload.supported_locales.1', 'fr');

        $this->asAdmin()->postJson('/api/v1/site-config/draft/publish')->assertOk();

        $this->assertSame(['en', 'fr'], $this->websiteDocument($this->config)['supported_locales']);
        $this->asAdmin()->getJson('/api/v1/site-config?locale=fr')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Lancement localisé')
            ->assertJsonPath('data.pages_seo.home.seo_title', 'Accueil localisé');
    }

    public function test_draft_rejects_unsupported_locales_and_non_localizable_paths(): void
    {
        $base = $this->asAdmin()->getJson('/api/v1/site-config/draft')->assertOk()->json('data.base_updated_at');
        $payload = HomepageSnapshot::fromConfig($this->config->fresh());
        $payload['supported_locales'] = ['en', 'fr'];
        $payload['localized_content'] = [
            'de' => ['hero_title' => 'Nicht unterstützt'],
            'fr' => ['contact_email' => 'unsafe@example.com'],
        ];

        $this->asAdmin()->putJson('/api/v1/site-config/draft', [
            'base_updated_at' => $base,
            'payload' => $payload,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'payload.localized_content.de',
                'payload.localized_content.fr.contact_email',
            ]);
    }
}
