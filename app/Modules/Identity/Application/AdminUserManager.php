<?php

namespace App\Modules\Identity\Application;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\StoreProvisioner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminUserManager
{
    public function __construct(
        private readonly StoreProvisioner $stores,
        private readonly UserPresenter $presenter,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $users = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
            ->with(['roles', 'store'])
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->orderBy('email')->paginate((int) ($filters['per_page'] ?? 25));
        $users->through(fn (User $user) => $this->presenter->administration($user));

        return $users;
    }

    public function show(User $user): array
    {
        abort_unless($user->isAdminLike(), 404);

        return $this->presenter->administration($user);
    }

    public function create(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $storeId = null;
            if ($attributes['role'] === 'admin') {
                $storeId = ! empty($attributes['store_id'])
                    ? (int) $attributes['store_id']
                    : $this->stores->provision([
                        'name' => $attributes['store_name'],
                        'slug' => $attributes['store_slug'] ?? Store::generateUniqueSlug($attributes['store_name']),
                        'status' => 'active',
                    ])->id;
            }
            $role = Role::firstOrCreate(['name' => $attributes['role']]);
            $user = User::create([
                'name' => $attributes['name'], 'email' => $attributes['email'],
                'password' => $attributes['password'], 'is_admin' => true, 'store_id' => $storeId,
                'status' => $attributes['status'] ?? User::STATUS_ACTIVE,
                'disabled_at' => ($attributes['status'] ?? null) === User::STATUS_DISABLED ? now() : null,
            ]);
            $user->roles()->sync([$role->id]);

            return $user->load(['roles', 'store']);
        });
    }

    public function update(User $user, array $attributes): User
    {
        $user->fill(collect($attributes)->except(['password', 'status'])->all());
        if (! empty($attributes['password'])) {
            $user->password = $attributes['password'];
        }
        if (array_key_exists('status', $attributes)) {
            $user->status = $attributes['status'];
            $user->disabled_at = $attributes['status'] === User::STATUS_DISABLED ? now() : null;
        }
        $user->save();

        return $user->load(['roles', 'store']);
    }

    public function delete(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $store = $user->store;
            $user->delete();
            if ($store && $store->slug !== Store::DEFAULT_SLUG && ! $store->users()->exists()) {
                $store->delete();

                return true;
            }

            return false;
        });
    }

    public function superAdminCount(bool $activeOnly = false): int
    {
        return User::whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->when($activeOnly, fn ($query) => $query->where('status', User::STATUS_ACTIVE))->count();
    }

    public function present(User $user): array
    {
        return $this->presenter->administration($user);
    }
}
