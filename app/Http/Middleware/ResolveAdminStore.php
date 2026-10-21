<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAdminStore
{
    public function __construct(protected CurrentStore $currentStore) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            // Preserve a storefront context for guest-facing routes where this
            // middleware deliberately runs after ResolveStorefrontStore.
            return $next($request);
        }

        // Authenticated resolution is authoritative. In particular, a Super
        // Admin without a store must not inherit a previous request's tenant.
        $this->currentStore->clear();

        if ($user->store_id) {
            $store = Store::find($user->store_id);

            if (! $store) {
                return response()->json([
                    'message' => 'Your account is linked to a store that no longer exists.',
                ], 403);
            }

            if (! $store->isActive()) {
                return response()->json([
                    'message' => 'Your store is inactive.',
                ], 403);
            }

            $this->currentStore->set($store);

            return $next($request);
        }

        // Legacy store-admin accounts created before store assignment was
        // mandatory operate on the explicit default store. Resolve it afresh;
        // never preserve whatever tenant happened to be in memory.
        if ($user->isAdminLike() && ! $user->isSuperAdmin()) {
            $defaultSlug = (string) config('storefront.default_store_slug', Store::DEFAULT_SLUG);
            $store = Store::query()->where('slug', $defaultSlug)->first();

            if (! $store) {
                return response()->json([
                    'message' => 'The default store is not available.',
                ], 403);
            }

            if (! $store->isActive()) {
                return response()->json([
                    'message' => 'Your store is inactive.',
                ], 403);
            }

            $this->currentStore->set($store, resolvedFromHost: false);

            return $next($request);
        }

        // Super admins and ordinary authenticated users without a store do not
        // get an automatic tenant. Platform operations use /super-admin/* and
        // bind Store/User directly; ordinary users cannot enter admin routes.
        return $next($request);
    }
}
