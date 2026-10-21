<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdateStoreRequest;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Modules\ControlPlane\Application\StoreAdministration;
use App\Support\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly StoreAdministration $stores,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'subscription_status' => ['sometimes', 'nullable', Rule::in([
                Store::SUBSCRIPTION_UNSUBSCRIBED,
                Store::SUBSCRIPTION_PENDING,
                Store::SUBSCRIPTION_ACTIVE,
                Store::SUBSCRIPTION_CANCELLED,
                Store::SUBSCRIPTION_EXPIRED,
                Store::SUBSCRIPTION_COMPED,
            ])],
            'domain_status' => ['sometimes', 'nullable', Rule::in(['verified', 'unverified', 'none'])],
        ]);

        return response()->json($this->stores->paginate($validated));
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'selected_id' => ['sometimes', 'nullable', 'integer', 'exists:stores,id'],
        ]);

        return response()->json(['data' => $this->stores->options($validated)]);
    }

    public function show(Store $store): JsonResponse
    {
        $store->load('subscriptionPlan')->loadCount('users');

        return response()->json([
            'data' => $this->stores->present($store),
        ]);
    }

    public function admins(Store $store): JsonResponse
    {
        return response()->json(['data' => $this->stores->admins($store)]);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();

        $store = $this->stores->update($store, $data);

        return response()->json([
            'data' => $this->stores->present($store),
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

        $this->stores->delete($store);

        return response()->json([
            'message' => 'Store deleted.',
        ]);
    }

    public function activate(Store $store): JsonResponse
    {
        return response()->json([
            'data' => $this->stores->present($this->stores->status($store, 'active')),
        ]);
    }

    public function deactivate(Store $store): JsonResponse
    {
        if ($store->slug === Store::DEFAULT_SLUG) {
            return response()->json([
                'message' => 'The default store cannot be deactivated.',
            ], 422);
        }

        return response()->json([
            'data' => $this->stores->present($this->stores->status($store, 'inactive')),
        ]);
    }

    /**
     * Grant platform access (comp) — used for grandfathered stores, trials and
     * manual reactivation. Idempotent; logs a `mode='comp'` subscriptions row.
     */
    public function comp(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['sometimes', 'nullable', 'integer', 'exists:subscription_plans,id'],
        ]);

        $plan = isset($validated['subscription_plan_id'])
            ? SubscriptionPlan::find((int) $validated['subscription_plan_id'])
            : $store->subscriptionPlan;

        $this->subscriptions->comp($store, $plan);

        return response()->json([
            'data' => $this->stores->present($store->fresh()->load('subscriptionPlan')->loadCount('users')),
        ]);
    }

    /**
     * Revoke a comp, returning the store to the (gated) unsubscribed state.
     */
    public function uncomp(Store $store): JsonResponse
    {
        if ($store->subscription_status !== Store::SUBSCRIPTION_COMPED) {
            return response()->json([
                'message' => 'This store is not comped.',
            ], 422);
        }

        $this->subscriptions->uncomp($store);

        return response()->json([
            'data' => $this->stores->present($store->fresh()->load('subscriptionPlan')->loadCount('users')),
        ]);
    }
}
