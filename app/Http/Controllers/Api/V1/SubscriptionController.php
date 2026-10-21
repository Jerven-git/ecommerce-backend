<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Application\SubscriptionBilling;
use App\Modules\Subscriptions\Application\SubscriptionOperationException;
use App\Modules\Subscriptions\Application\SubscriptionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionBilling $billing,
        private readonly SubscriptionQuery $subscriptions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->subscriptions->status($request->user()),
        ]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->plans()]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['nullable', 'string', 'exists:subscription_plans,slug'],
        ]);

        try {
            $url = $this->billing->checkout($request->user(), $validated['plan'] ?? null);
        } catch (SubscriptionOperationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage(), ...$exception->context],
                $exception->status,
            );
        }

        return response()->json(['url' => $url]);
    }

    public function portal(Request $request): JsonResponse
    {
        try {
            $url = $this->billing->portal($request->user());
        } catch (SubscriptionOperationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage(), ...$exception->context],
                $exception->status,
            );
        }

        return response()->json(['url' => $url]);
    }
}
