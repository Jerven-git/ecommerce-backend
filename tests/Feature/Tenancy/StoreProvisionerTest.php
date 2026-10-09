<?php

namespace Tests\Feature\Tenancy;

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Support\Tenancy\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_store_with_its_required_site_config(): void
    {
        $store = app(StoreProvisioner::class)->provision([
            'name' => 'Complete Tenant',
            'slug' => 'complete-tenant',
            'status' => 'active',
        ]);

        $config = SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->firstOrFail();

        $this->assertSame('My Store', $config->site_name);
    }

    public function test_it_rolls_back_the_store_when_site_config_creation_fails(): void
    {
        $provisioner = new class extends StoreProvisioner
        {
            protected function createSiteConfig(Store $store): void
            {
                throw new \RuntimeException('Simulated site config failure.');
            }
        };

        try {
            $provisioner->provision([
                'name' => 'Partial Tenant',
                'slug' => 'partial-tenant',
                'status' => 'active',
            ]);

            $this->fail('Provisioning should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated site config failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('stores', ['slug' => 'partial-tenant']);
    }

    public function test_repair_migration_backfills_an_existing_tenant_without_config(): void
    {
        $store = Store::factory()->create(['slug' => 'legacy-tenant']);

        $this->assertDatabaseMissing('site_config', ['store_id' => $store->id]);

        $migration = require database_path('migrations/2026_10_09_000001_backfill_missing_store_site_config.php');
        $migration->up();

        $this->assertDatabaseHas('site_config', ['store_id' => $store->id]);
    }
}
