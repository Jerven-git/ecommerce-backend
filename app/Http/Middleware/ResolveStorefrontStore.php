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
        $host = strtolower($request->getHost());

        // 1. Exact domain match — custom domain (e.g. nazareck.com)
        $store = Store::where('domain', $host)->first();

        if ($store) {
            return $this->resolve($store, $request, $next, resolvedFromHost: true);
        }

        // 2. Subdomain extraction (e.g. acme.yourdomain.com)
        $baseDomain = strtolower((string) config('storefront.base_domain'));
        $slug = $this->extractStoreSlug($host, $baseDomain);

        if ($slug !== null) {
            $store = Store::query()->where('slug', $slug)->first();

            if (! $store) {
                return response()->json([
                    'message' => 'Store not found for host '.$host.'.',
                ], 404);
            }

            return $this->resolve($store, $request, $next, resolvedFromHost: true);
        }

        // 3. Bare apex / host not bound to any store. Keep the default store set
        // so shared endpoints keep working, but flag that the host did NOT
        // resolve a storefront — the SPA routes these visitors to the admin
        // login instead of rendering the default store.
        $defaultSlug = (string) config('storefront.default_store_slug', Store::DEFAULT_SLUG);
        $store = Store::query()->where('slug', $defaultSlug)->first();

        if (! $store) {
            return response()->json([
                'message' => 'Store not found for host '.$host.'.',
            ], 404);
        }

        return $this->resolve($store, $request, $next, resolvedFromHost: false);
    }

    /**
     * Set the resolved store on the tenancy context and continue, or 404 when
     * the store is inactive.
     */
    protected function resolve(Store $store, Request $request, Closure $next, bool $resolvedFromHost): Response
    {
        if (! $store->isActive()) {
            return response()->json([
                'message' => 'This store is currently unavailable.',
            ], 404);
        }

        $this->currentStore->set($store, $resolvedFromHost);

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
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        return $slug === '' ? null : $slug;
    }
}
