<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use App\Support\Tenancy\HostStoreResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveStorefrontStore
{
    public function __construct(
        protected CurrentStore $currentStore,
        protected HostStoreResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $canonical = $this->resolver->canonicalHost($host);

        // 1. Hosts on the platform's own domain resolve by slug, and only by
        // slug. Checking stores.domain first would let a custom-domain row
        // claim another store's subdomain — or the apex that serves the admin
        // login — since wildcard DNS already points those here.
        if ($this->resolver->isPlatformHost($canonical)) {
            $slug = $this->resolver->extractSlug($canonical, $this->resolver->baseDomain());

            // The bare base domain is not a storefront; fall through to the
            // default store, flagged as not host-resolved.
            if ($slug === null) {
                return $this->resolveDefault($request, $next, $host);
            }

            $store = Store::query()->where('slug', $slug)->first();

            if (! $store) {
                return $this->notFound($host);
            }

            return $this->resolve($store, $request, $next, resolvedFromHost: true);
        }

        // 2. Custom domain, verified only. An unverified claim resolves to
        // nothing, so squatting a domain cannot take it away from its owner.
        $store = $this->resolver->storeForCustomDomain($canonical);

        if ($store) {
            return $this->resolve($store, $request, $next, resolvedFromHost: true);
        }

        // 3. Host not bound to any store. Keep the default store set so shared
        // endpoints keep working, but flag that the host did NOT resolve a
        // storefront — the SPA routes these visitors to the admin login
        // instead of rendering the default store.
        return $this->resolveDefault($request, $next, $host);
    }

    protected function resolveDefault(Request $request, Closure $next, string $host): Response
    {
        $defaultSlug = (string) config('storefront.default_store_slug', Store::DEFAULT_SLUG);
        $store = Store::query()->where('slug', $defaultSlug)->first();

        if (! $store) {
            return $this->notFound($host);
        }

        return $this->resolve($store, $request, $next, resolvedFromHost: false);
    }

    protected function notFound(string $host): Response
    {
        return response()->json([
            'message' => 'Store not found for host '.$host.'.',
        ], 404);
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
}
