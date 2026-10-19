<?php

namespace App\Support\SiteConfig;

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Support\WebsiteEditor\WebsiteDocumentStore;
use Illuminate\Http\Request;

final class SiteConfigResolver
{
    private const RELATIONS = ['storefrontMedia'];

    public function __construct(private readonly WebsiteDocumentStore $documents) {}

    public function resolve(Request $request): SiteConfig
    {
        if ($request->user()?->store_id) {
            return $this->forStore((int) $request->user()->store_id, true)->loadMissing(self::RELATIONS);
        }

        $storeSlug = $request->query('store');
        if (is_string($storeSlug) && $storeSlug !== '') {
            $store = Store::query()
                ->where('slug', $storeSlug)
                ->where('status', 'active')
                ->first();

            if ($store) {
                $config = $this->forStore((int) $store->id);
                if ($config) {
                    return $config->loadMissing(self::RELATIONS);
                }
            }
        }

        $config = SiteConfig::with(self::RELATIONS)->first()
            ?? SiteConfig::firstOrCreateForDefaultStore()->load(self::RELATIONS);

        $this->documents->ensure($config);

        return $config;
    }

    public function forAuthenticatedStore(Request $request, bool $create = true): ?SiteConfig
    {
        $storeId = (int) $request->user()?->store_id;
        if ($storeId > 0) {
            return $this->forStore($storeId, $create);
        }

        $config = SiteConfig::query()->first()
            ?? ($create ? SiteConfig::query()->create([]) : null);

        if ($config) {
            $this->documents->ensure($config);
        }

        return $config;
    }

    private function forStore(int $storeId, bool $create = false): ?SiteConfig
    {
        $query = SiteConfig::withoutGlobalScope(StoreScope::class)->where('store_id', $storeId);
        $config = $query->first();

        if (! $config && $create) {
            $config = SiteConfig::withoutGlobalScope(StoreScope::class)->create(['store_id' => $storeId]);
        }

        if ($config) {
            $this->documents->ensure($config);
        }

        return $config;
    }
}
