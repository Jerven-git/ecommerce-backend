<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $users = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
            ->with(['roles', 'store'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->orderBy('email')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $users->through(fn (User $user) => $this->serializeUser($user));

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        abort_unless($user->isAdminLike(), 404);
        $user->load(['roles', 'store']);

        return response()->json([
            'data' => $this->serializeUser($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'super_admin'])],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'store_name' => ['nullable', 'string', 'max:255'],
            'store_slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:stores,slug'],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        // An admin must land in a store: either an existing one (store_id) or a
        // freshly provisioned one (store_name). Super admins never get a store.
        if ($validated['role'] === 'admin' && empty($validated['store_id']) && empty($validated['store_name'])) {
            return response()->json([
                'message' => 'Select an existing store to assign this admin to, or provide a name for a new store.',
            ], 422);
        }

        $user = DB::transaction(function () use ($validated) {
            $storeId = null;

            if ($validated['role'] === 'admin') {
                if (! empty($validated['store_id'])) {
                    $storeId = (int) $validated['store_id'];
                } else {
                    $slug = $validated['store_slug'] ?? $this->generateUniqueStoreSlug($validated['store_name']);

                    $store = Store::create([
                        'name' => $validated['store_name'],
                        'slug' => $slug,
                        'status' => 'active',
                    ]);

                    SiteConfig::withoutGlobalScope(StoreScope::class)->create([
                        'store_id' => $store->id,
                    ]);

                    $storeId = $store->id;
                }
            }

            $role = Role::query()->firstOrCreate(['name' => $validated['role']]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_admin' => true,
                'store_id' => $storeId,
                'status' => $validated['status'] ?? User::STATUS_ACTIVE,
                'disabled_at' => ($validated['status'] ?? null) === User::STATUS_DISABLED ? now() : null,
            ]);

            $user->roles()->sync([$role->id]);

            return $user;
        });

        $user->load(['roles', 'store']);

        return response()->json([
            'data' => $this->serializeUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isAdminLike(), 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'store_id' => ['sometimes', 'required', 'integer', Rule::exists('stores', 'id')],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        if ($request->has('role')) {
            return response()->json([
                'message' => 'Role changes are not allowed. Delete the account and create a new one with the desired role.',
            ], 422);
        }

        if ($request->has('store_id') && $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Super admins operate across all stores and cannot be assigned to one.',
            ], 422);
        }

        if (
            array_key_exists('status', $validated)
            && $validated['status'] === User::STATUS_DISABLED
            && $user->isSuperAdmin()
            && $this->activeSuperAdminCount() <= 1
        ) {
            return response()->json([
                'message' => 'You must keep at least one active super admin account.',
            ], 422);
        }

        if (
            array_key_exists('status', $validated)
            && $validated['status'] === User::STATUS_DISABLED
            && (int) $request->user()->id === (int) $user->id
        ) {
            return response()->json([
                'message' => 'You cannot disable your own account.',
            ], 422);
        }

        $user->fill(collect($validated)->except(['password', 'status'])->all());

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        if (array_key_exists('status', $validated)) {
            $user->status = $validated['status'];
            $user->disabled_at = $validated['status'] === User::STATUS_DISABLED ? now() : null;
        }

        $user->save();
        $user->load(['roles', 'store']);

        return response()->json([
            'data' => $this->serializeUser($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isAdminLike(), 404);

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            return response()->json([
                'message' => 'You must keep at least one super admin account.',
            ], 422);
        }

        $storeDeleted = DB::transaction(function () use ($user) {
            $store = $user->store;

            $user->delete();

            // A store can now have several admins. Only soft-delete it once the
            // last admin is gone (never the default store) so it can be restored
            // if needed; otherwise the remaining admins keep operating it.
            if ($store && $store->slug !== Store::DEFAULT_SLUG && ! $store->users()->exists()) {
                $store->delete();

                return true;
            }

            return false;
        });

        return response()->json([
            'message' => $storeDeleted
                ? 'Admin account deleted. Its store had no other admins and was removed.'
                : 'Admin account deleted.',
        ]);
    }

    private function generateUniqueStoreSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $i = 1;

        while (Store::withTrashed()->where('slug', $slug)->exists()) {
            $i++;
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function superAdminCount(): int
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->count();
    }

    private function activeSuperAdminCount(): int
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->where('status', User::STATUS_ACTIVE)
            ->count();
    }

    private function serializeUser(User $user): array
    {
        $roles = $user->roleNames();

        return array_merge($user->toArray(), [
            'roles' => $roles,
            'is_admin' => in_array('admin', $roles, true) || in_array('super_admin', $roles, true),
            'is_super_admin' => in_array('super_admin', $roles, true),
            'role' => in_array('super_admin', $roles, true) ? 'super_admin' : 'admin',
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'slug' => $user->store->slug,
            ] : null,
        ]);
    }
}
