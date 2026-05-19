<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveStorefrontStore
{
    public function __construct(protected CurrentStore $currentStore) {}

    public function handle(Request $request, Closure $next): Response
    {
        $baseDomain = strtolower((string) config('storefront.base_domain'));
        $defaultSlug = (string) config('storefront.default_store_slug', Store::DEFAULT_SLUG);

        $host = strtolower($request->getHost());

        $slug = $this->extractStoreSlug($host, $baseDomain) ?? $defaultSlug;

        $store = Store::query()->where('slug', $slug)->first();

        if (! $store) {
            // Subdomain that doesn't match any store -> 404.
            // Bare base-domain hits fall through to the default slug above, so
            // a missing default store here means the install is misconfigured.
            return response()->json([
                'message' => 'Store not found for host '.$host.'.',
            ], 404);
        }

        if (! $store->isActive()) {
            return response()->json([
                'message' => 'This store is currently unavailable.',
            ], 404);
        }

        $this->currentStore->set($store);

        return $next($request);
    }

    /**
     * Derive a store slug from the request host, or null if the host equals
     * the base domain itself (caller falls back to the default store).
     */
    protected function extractStoreSlug(string $host, string $baseDomain): ?string
    {
        if ($baseDomain === '' || $host === $baseDomain) {
            return null;
        }

        $suffix = '.'.$baseDomain;

        if (! str_ends_with($host, $suffix)) {
            // Host doesn't sit under the configured base domain (e.g. an IP,
            // a custom domain, or a stray request). Fall back to default.
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        return $slug === '' ? null : $slug;
    }
}
