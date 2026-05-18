<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        $store = Store::where('slug', Store::DEFAULT_SLUG)->first();

        if ($store) {
            $this->currentStore->set($store);
            Log::info('Authenticated user with no store_id defaulted to default store', [
                'user_id' => $user->id,
                'store_id' => $store->id,
                'is_super_admin' => $user->isSuperAdmin(),
            ]);
        }

        return $next($request);
    }
}
