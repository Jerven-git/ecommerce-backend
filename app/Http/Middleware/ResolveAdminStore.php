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
            return $next($request);
        }

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

        // Super admins (and any other authenticated user with no store_id) do
        // not get an automatic tenant. They use /super-admin/* endpoints that
        // operate outside tenant scope by design. The legacy default-store
        // fallback was removed because it leaked the default store's settings
        // into the super-admin context.
        return $next($request);
    }
}
