<?php

namespace App\Support\Tenancy;

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Support\WebsiteEditor\WebsiteDocumentDefaults;
use App\Support\WebsiteEditor\WebsiteDocumentStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreProvisioner
{
    /**
     * Create the store and its required tenant configuration as one unit.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function provision(array $attributes): Store
    {
        return DB::transaction(function () use ($attributes): Store {
            $store = Store::create($attributes);

            $this->createSiteConfig($store);

            return $store;
        });
    }

    protected function createSiteConfig(Store $store): void
    {
        $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
        ]);

        if (Schema::hasTable('site_config_documents')) {
            app(WebsiteDocumentStore::class)->write($config, WebsiteDocumentDefaults::published());
        }
    }
}
