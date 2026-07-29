<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreStoreRequest;
use App\Http\Requests\SuperAdmin\UpdateStoreRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $stores = Store::query()
            ->withCount('users')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $stores->through(fn (Store $store) => $this->serialize($store));

        return response()->json($stores);
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'selected_id' => ['sometimes', 'nullable', 'integer', 'exists:stores,id'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $selectedId = isset($validated['selected_id']) ? (int) $validated['selected_id'] : null;

        $stores = Store::query()
            ->select(['id', 'name', 'slug'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        if ($selectedId && ! $stores->contains('id', $selectedId)) {
            $selected = Store::query()->select(['id', 'name', 'slug'])->find($selectedId);
            if ($selected) {
                $stores->prepend($selected);
            }
        }

        return response()->json(['data' => $stores->take(20)->values()]);
    }

    public function show(Store $store): JsonResponse
    {
        $store->loadCount('users');

        return response()->json([
            'data' => $this->serialize($store),
        ]);
    }

    public function admins(Store $store): JsonResponse
    {
        $users = User::query()
            ->where('store_id', $store->id)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
            ->with(['roles', 'store'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->serializeAdmin($user))->values(),
        ]);
    }

    public function store(StoreStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        // A freshly claimed domain is always unverified; it starts routing only
        // once its DNS is shown to point here.
        $data['domain_verified_at'] = null;

        $store = Store::create($data);
        $store->loadCount('users');

        return response()->json([
            'data' => $this->serialize($store),
        ], 201);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('domain', $data)) {
            // Compare against the canonical form the model would persist, so
            // re-saving the same domain doesn't needlessly drop verification.
            $store->domain = $data['domain'];

            if ($store->isDirty('domain')) {
                $data['domain_verified_at'] = null;
            }
        }

        $store->update($data);
        $store->loadCount('users');

        return response()->json([
            'data' => $this->serialize($store),
        ]);
    }

    public function destroy(Store $store): JsonResponse
    {
        if ($store->slug === Store::DEFAULT_SLUG) {
            return response()->json([
                'message' => 'The default store cannot be deleted.',
            ], 422);
        }

        if ($store->users()->exists()) {
            return response()->json([
                'message' => 'Reassign or remove this store\'s admin users before deleting it.',
            ], 422);
        }

        $store->delete();

        return response()->json([
            'message' => 'Store deleted.',
        ]);
    }

    public function activate(Store $store): JsonResponse
    {
        $store->update(['status' => 'active']);

        return response()->json([
            'data' => $this->serialize($store->fresh()->loadCount('users')),
        ]);
    }

    public function deactivate(Store $store): JsonResponse
    {
        if ($store->slug === Store::DEFAULT_SLUG) {
            return response()->json([
                'message' => 'The default store cannot be deactivated.',
            ], 422);
        }

        $store->update(['status' => 'inactive']);

        return response()->json([
            'data' => $this->serialize($store->fresh()->loadCount('users')),
        ]);
    }

    private function serialize(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'domain' => $store->domain,
            'domain_verified_at' => $store->domain_verified_at,
            'domain_verified' => $store->hasVerifiedDomain(),
            'status' => $store->status,
            'is_default' => $store->slug === Store::DEFAULT_SLUG,
            'default_currency_id' => $store->default_currency_id,
            'users_count' => $store->users_count ?? 0,
            'created_at' => $store->created_at,
            'updated_at' => $store->updated_at,
            'deleted_at' => $store->deleted_at,
        ];
    }

    private function serializeAdmin(User $user): array
    {
        $roles = $user->roleNames();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => in_array('super_admin', $roles, true) ? 'super_admin' : 'admin',
            'is_super_admin' => in_array('super_admin', $roles, true),
            'status' => $user->status,
            'disabled_at' => $user->disabled_at,
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'slug' => $user->store->slug,
            ] : null,
            'created_at' => $user->created_at,
        ];
    }
}
