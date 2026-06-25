<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreStoreRequest;
use App\Http\Requests\SuperAdmin\UpdateStoreRequest;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function index(): JsonResponse
    {
        $stores = Store::query()
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $stores->map(fn (Store $store) => $this->serialize($store))->values(),
        ]);
    }

    public function show(Store $store): JsonResponse
    {
        $store->loadCount('users');

        return response()->json([
            'data' => $this->serialize($store),
        ]);
    }

    public function store(StoreStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['domain'])) {
            $data['domain'] = Str::lower($data['domain']);
        }

        $store = Store::create($data);
        $store->loadCount('users');

        return response()->json([
            'data' => $this->serialize($store),
        ], 201);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['domain'])) {
            $data['domain'] = Str::lower($data['domain']);
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
            'status' => $store->status,
            'is_default' => $store->slug === Store::DEFAULT_SLUG,
            'default_currency_id' => $store->default_currency_id,
            'users_count' => $store->users_count ?? 0,
            'created_at' => $store->created_at,
            'updated_at' => $store->updated_at,
            'deleted_at' => $store->deleted_at,
        ];
    }
}
