<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Http\Middleware\SubscriptionMiddleware;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDocument;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\StoreProvisioner;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteDocumentDefaults;
use App\Support\WebsiteEditor\WebsiteDocumentStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_provisioning_writes_a_versioned_presentation_document(): void
    {
        $store = app(StoreProvisioner::class)->provision([
            'name' => 'Document tenant',
            'slug' => 'document-tenant',
            'status' => 'active',
        ]);
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->firstOrFail();

        $document = SiteConfigDocument::withoutGlobalScope(StoreScope::class)
            ->where('site_config_id', $config->id)
            ->firstOrFail();

        $this->assertSame($store->id, $document->store_id);
        $this->assertSame(WebsiteDocumentStore::SCHEMA_VERSION, $document->schema_version);
        $this->assertSame('Welcome to Our Store', $document->payload['hero_title']);
        $this->assertArrayNotHasKey('favorites_enabled', $document->payload);
        $this->assertArrayNotHasKey('currency_code', $document->payload);
        $this->assertArrayNotHasKey('modules_enabled', $document->payload);
    }

    public function test_migration_backfills_existing_site_config_rows(): void
    {
        Schema::drop('site_config_documents');
        Schema::table('site_config', function (Blueprint $table): void {
            $table->string('hero_title')->nullable();
        });
        $store = Store::factory()->create();
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
        ]);
        DB::table('site_config')->where('id', $config->id)->update(['hero_title' => 'Pre-migration hero']);
        $config->refresh();

        $migration = require database_path('migrations/2026_10_19_000001_create_site_config_documents_table.php');
        $migration->up();

        $document = SiteConfigDocument::withoutGlobalScope(StoreScope::class)
            ->where('site_config_id', $config->id)
            ->firstOrFail();

        $this->assertSame($store->id, $document->store_id);
        $this->assertSame('Pre-migration hero', $document->payload['hero_title']);
        $this->assertSame(WebsiteDocumentStore::SCHEMA_VERSION, $document->schema_version);
    }

    public function test_direct_settings_update_writes_presentation_to_the_document(): void
    {
        $store = Store::factory()->create();
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
        ]);
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $store->id]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        $this->actingAs($admin)
            ->withoutMiddleware([SessionLifetimeMiddleware::class, SubscriptionMiddleware::class])
            ->patchJson('/api/v1/site-config', [
                'hero_title' => 'After',
                'favorites_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'After');

        $document = SiteConfigDocument::withoutGlobalScope(StoreScope::class)
            ->where('site_config_id', $config->id)
            ->firstOrFail();

        $this->assertSame('After', $document->payload['hero_title']);
        $this->assertArrayNotHasKey('favorites_enabled', $document->payload);
        $this->assertTrue($config->fresh()->favorites_enabled);
        $this->assertSame('After', HomepageSnapshot::fromConfig($config->fresh())['hero_title']);
    }

    public function test_snapshot_prefers_document_content_and_falls_back_to_legacy_columns(): void
    {
        $store = Store::factory()->create();
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
        ]);
        $document = app(WebsiteDocumentStore::class)->write($config, WebsiteDocumentDefaults::published());
        $payload = $document->payload;
        $payload['hero_title'] = 'Document hero';
        $document->update(['payload' => $payload]);
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $store->id]);

        $this->assertSame('Welcome to Our Store', HomepageSnapshot::fromLegacyConfig($config->fresh())['hero_title']);
        $this->assertSame('Document hero', HomepageSnapshot::fromConfig($config->fresh())['hero_title']);
        $this->actingAs($admin)
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Document hero');

        $document->delete();

        $this->assertSame('Welcome to Our Store', HomepageSnapshot::fromConfig($config->fresh())['hero_title']);
    }

    public function test_snapshot_falls_back_when_document_schema_version_is_not_supported(): void
    {
        $store = Store::factory()->create();
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
        ]);
        $document = app(WebsiteDocumentStore::class)->write($config, WebsiteDocumentDefaults::published());
        $payload = $document->payload;
        $payload['hero_title'] = 'Future document hero';
        $document->update([
            'schema_version' => WebsiteDocumentStore::SCHEMA_VERSION + 1,
            'payload' => $payload,
        ]);

        $this->assertSame('Welcome to Our Store', HomepageSnapshot::fromConfig($config->fresh())['hero_title']);
    }

    public function test_document_rows_remain_bound_to_their_tenant(): void
    {
        $firstStore = Store::factory()->create();
        $secondStore = Store::factory()->create();
        $first = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $firstStore->id,
        ]);
        $second = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $secondStore->id,
        ]);
        app(WebsiteDocumentStore::class)->write($first, [
            ...WebsiteDocumentDefaults::published(),
            'hero_title' => 'First hero',
        ]);
        app(WebsiteDocumentStore::class)->write($second, [
            ...WebsiteDocumentDefaults::published(),
            'hero_title' => 'Second hero',
        ]);

        $firstDocument = SiteConfigDocument::withoutGlobalScope(StoreScope::class)
            ->where('site_config_id', $first->id)
            ->firstOrFail();
        $secondDocument = SiteConfigDocument::withoutGlobalScope(StoreScope::class)
            ->where('site_config_id', $second->id)
            ->firstOrFail();

        $this->assertSame($firstStore->id, $firstDocument->store_id);
        $this->assertSame('First hero', $firstDocument->payload['hero_title']);
        $this->assertSame($secondStore->id, $secondDocument->store_id);
        $this->assertSame('Second hero', $secondDocument->payload['hero_title']);
    }

    public function test_fresh_schema_keeps_presentation_fields_out_of_site_config(): void
    {
        foreach (['hero_title', 'about_content', 'pages_seo', 'footer', 'default_locale'] as $column) {
            $this->assertFalse(Schema::hasColumn('site_config', $column), $column.' should be document-backed.');
        }

        foreach (['theme', 'contact_email', 'homepage_watch_shop', 'modules_enabled', 'currency_code'] as $column) {
            $this->assertTrue(Schema::hasColumn('site_config', $column), $column.' should remain operational.');
        }
    }

    public function test_resolver_repairs_a_missing_document_for_an_existing_config(): void
    {
        $store = Store::factory()->create();
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
        ]);
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $store->id]);

        $this->assertDatabaseMissing('site_config_documents', ['site_config_id' => $config->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Welcome to Our Store');

        $this->assertDatabaseHas('site_config_documents', [
            'site_config_id' => $config->id,
            'store_id' => $store->id,
            'schema_version' => WebsiteDocumentStore::SCHEMA_VERSION,
        ]);
    }

    public function test_direct_media_changes_keep_document_urls_current(): void
    {
        Storage::fake('public');
        $store = Store::factory()->create();
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
        ]);
        app(WebsiteDocumentStore::class)->write($config, WebsiteDocumentDefaults::published());
        $admin = User::factory()->create(['is_admin' => true, 'store_id' => $store->id]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        $this->actingAs($admin)->withoutMiddleware([
            SessionLifetimeMiddleware::class,
            SubscriptionMiddleware::class,
        ]);

        $first = $this->post('/api/v1/site-config/media/about', [
            'file' => UploadedFile::fake()->image('first-about.png'),
        ])->assertOk();
        $second = $this->post('/api/v1/site-config/media/about', [
            'file' => UploadedFile::fake()->image('second-about.png'),
        ])->assertOk();

        $this->assertNotSame($first->json('url'), $second->json('url'));
        $this->assertSame($second->json('url'), $this->websiteDocument($config->fresh())['about_image_url']);

        $this->deleteJson('/api/v1/site-config/media/about')->assertNoContent();

        $this->assertNull($this->websiteDocument($config->fresh())['about_image_url']);
    }
}
