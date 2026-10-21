<?php

namespace App\Modules\ControlPlane\Application;

use App\Models\Store;
use App\Models\User;
use App\Modules\Identity\Application\UserPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class StoreAdministration
{
    public function __construct(
        private readonly StorePresenter $presenter,
        private readonly UserPresenter $users,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $stores = Store::with('subscriptionPlan')->withCount('users')
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('domain', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['subscription_status'] ?? null, fn ($query, $status) => $query->where('subscription_status', $status))
            ->when($filters['domain_status'] ?? null, function ($query, $status): void {
                match ($status) {
                    'verified' => $query->whereNotNull('domain')->whereNotNull('domain_verified_at'),
                    'unverified' => $query->whereNotNull('domain')->whereNull('domain_verified_at'),
                    'none' => $query->whereNull('domain'),
                };
            })->orderBy('name')->paginate((int) ($filters['per_page'] ?? 25));
        $stores->through(fn (Store $store) => $this->presenter->present($store));

        return $stores;
    }

    public function options(array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $selectedId = isset($filters['selected_id']) ? (int) $filters['selected_id'] : null;
        $stores = Store::select(['id', 'name', 'slug'])
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->orderBy('name')->limit(20)->get();
        if ($selectedId && ! $stores->contains('id', $selectedId)) {
            $selected = Store::select(['id', 'name', 'slug'])->find($selectedId);
            if ($selected) {
                $stores->prepend($selected);
            }
        }

        return $stores->take(20)->values();
    }

    public function admins(Store $store): Collection
    {
        return User::where('store_id', $store->id)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
            ->with(['roles', 'store'])->orderBy('name')->get()
            ->map(fn (User $user) => $this->adminSummary($user))->values();
    }

    public function update(Store $store, array $attributes): Store
    {
        if (array_key_exists('domain', $attributes)) {
            $store->domain = $attributes['domain'];
            if ($store->isDirty('domain')) {
                $attributes['domain_verified_at'] = null;
            }
        }
        $store->update($attributes);

        return $store->load('subscriptionPlan')->loadCount('users');
    }

    public function delete(Store $store): void
    {
        $store->delete();
    }

    public function status(Store $store, string $status): Store
    {
        $store->update(['status' => $status]);

        return $store->fresh()->load('subscriptionPlan')->loadCount('users');
    }

    public function present(Store $store): array
    {
        return $this->presenter->present($store);
    }

    private function adminSummary(User $user): array
    {
        $data = $this->users->administration($user);

        return collect($data)->only([
            'id', 'name', 'email', 'role', 'is_super_admin', 'status', 'disabled_at', 'store', 'created_at',
        ])->all();
    }
}
