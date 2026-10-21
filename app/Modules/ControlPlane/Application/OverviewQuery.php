<?php

namespace App\Modules\ControlPlane\Application;

use App\Models\Store;
use App\Models\User;

class OverviewQuery
{
    public function get(): array
    {
        $stores = Store::selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) as inactive")
            ->selectRaw('SUM(CASE WHEN domain IS NOT NULL AND domain_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified_domains')
            ->selectRaw('SUM(CASE WHEN domain IS NOT NULL AND domain_verified_at IS NULL THEN 1 ELSE 0 END) as unverified_domains')
            ->selectRaw('SUM(CASE WHEN domain IS NULL THEN 1 ELSE 0 END) as no_domain')->first();
        $admins = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']));
        $adminTotals = (clone $admins)->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")->first();

        return [
            'stores' => [
                'total' => (int) ($stores->total ?? 0), 'active' => (int) ($stores->active ?? 0),
                'inactive' => (int) ($stores->inactive ?? 0),
                'verified_domains' => (int) ($stores->verified_domains ?? 0),
                'unverified_domains' => (int) ($stores->unverified_domains ?? 0),
                'no_domain' => (int) ($stores->no_domain ?? 0),
            ],
            'users' => ['total' => User::whereNotNull('store_id')->count()],
            'admins' => [
                'total' => (int) ($adminTotals->total ?? 0), 'active' => (int) ($adminTotals->active ?? 0),
                'super' => (clone $admins)->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))->count(),
            ],
            'newest_stores' => Store::withCount('users')->latest('created_at')->limit(5)->get()
                ->map(fn (Store $store) => [
                    'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug,
                    'domain' => $store->domain, 'domain_verified_at' => $store->domain_verified_at,
                    'domain_verified' => $store->hasVerifiedDomain(), 'status' => $store->status,
                    'is_default' => $store->slug === Store::DEFAULT_SLUG, 'users_count' => $store->users_count,
                    'created_at' => $store->created_at, 'updated_at' => $store->updated_at,
                    'deleted_at' => $store->deleted_at,
                ])->values(),
        ];
    }
}
