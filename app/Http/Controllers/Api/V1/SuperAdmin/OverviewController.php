<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $storeTotals = Store::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) as inactive")
            ->selectRaw('SUM(CASE WHEN domain IS NOT NULL AND domain_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified_domains')
            ->first();

        $adminQuery = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']));

        $adminTotals = (clone $adminQuery)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->first();

        $superAdmins = (clone $adminQuery)
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->count();

        $storeUsers = User::query()->whereNotNull('store_id')->count();

        $newestStores = Store::query()
            ->withCount('users')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'domain' => $store->domain,
                'domain_verified_at' => $store->domain_verified_at,
                'domain_verified' => $store->hasVerifiedDomain(),
                'status' => $store->status,
                'is_default' => $store->slug === Store::DEFAULT_SLUG,
                'default_currency_id' => $store->default_currency_id,
                'users_count' => $store->users_count,
                'created_at' => $store->created_at,
                'updated_at' => $store->updated_at,
                'deleted_at' => $store->deleted_at,
            ])
            ->values();

        return response()->json([
            'data' => [
                'stores' => [
                    'total' => (int) ($storeTotals->total ?? 0),
                    'active' => (int) ($storeTotals->active ?? 0),
                    'inactive' => (int) ($storeTotals->inactive ?? 0),
                    'verified_domains' => (int) ($storeTotals->verified_domains ?? 0),
                ],
                'users' => ['total' => $storeUsers],
                'admins' => [
                    'total' => (int) ($adminTotals->total ?? 0),
                    'active' => (int) ($adminTotals->active ?? 0),
                    'super' => $superAdmins,
                ],
                'newest_stores' => $newestStores,
            ],
        ]);
    }
}
