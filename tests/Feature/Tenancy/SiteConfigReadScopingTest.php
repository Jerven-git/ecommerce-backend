<?php

namespace Tests\Feature\Tenancy;

use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        SiteConfig::withoutGlobalScope(StoreScope::class)->updateOrCreate(
            ['store_id' => $this->defaultStore->id],
            ['site_name' => 'Default Storefront', 'hero_title' => 'Default Hero']
        );

        $this->newStore = Store::factory()->create(['name' => 'Watch World']);
        SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $this->newStore->id,
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
            ->assertJsonPath('data.hero_title', 'Default Hero')
            ->assertJsonPath('data.theme.page_transition_enabled', true)
            ->assertJsonPath('data.theme.page_transition_style', 'curtain');
    }

    public function test_authenticated_admin_sees_their_own_store_config_via_public_endpoint(): void
    {
        $this->actingAs($this->newAdmin)
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'Watch World Hero');
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
        $this->assertSame('Default Hero', $defaultConfig->hero_title);
    }

    public function test_admin_page_transition_settings_round_trip_through_public_read(): void
    {
        $this->actingAs($this->newAdmin)
            ->patchJson('/api/v1/site-config', [
                'theme' => [
                    'page_transition_enabled' => false,
                    'page_transition_style' => 'brush',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.theme.page_transition_enabled', false)
            ->assertJsonPath('data.theme.page_transition_style', 'brush');

        $this->actingAs($this->newAdmin)
            ->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.theme.page_transition_enabled', false)
            ->assertJsonPath('data.theme.page_transition_style', 'brush');
    }

    public function test_admin_page_transition_rejects_an_unknown_style(): void
    {
        $this->actingAs($this->newAdmin)
            ->patchJson('/api/v1/site-config', [
                'theme' => [
                    'page_transition_enabled' => true,
                    'page_transition_style' => 'spin-everything',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme.page_transition_style');
    }
}
