<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Subscriptions\Stripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private Stripe $stripe) {}

    /**
     * Current subscription state for the authenticated admin's store. Not
     * gated: a store suspended for non-payment still needs this to render the
     * subscribe-now screen.
     */
    public function show(Request $request): JsonResponse
    {
        $store = $this->storeOf($request->user());

        if ($store === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => $this->serialize($store),
        ]);
    }

    /**
     * Sellable plans for the public subscribe page.
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'interval' => $plan->interval,
                'price_cents' => $plan->price_cents,
                'setup_fee_cents' => $plan->setup_fee_cents,
                'features' => $plan->features,
            ]);

        return response()->json(['data' => $plans]);
    }

    /**
     * Kick off Stripe Checkout. Works for gated stores (paying to unlock) and
     * active stores (upgrading/renewing).
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['nullable', 'string', 'exists:subscription_plans,slug'],
        ]);

        $store = $this->storeOf($request->user());

        if ($store === null) {
            return response()->json(['message' => 'No store on this account.'], 422);
        }

        $plan = isset($validated['plan'])
            ? SubscriptionPlan::query()->where('slug', $validated['plan'])->where('is_active', true)->firstOrFail()
            : SubscriptionPlan::query()->where('is_active', true)->orderBy('price_cents')->first();

        if ($plan === null) {
            return response()->json(['message' => 'No active subscription plans are available yet.'], 422);
        }

        $customerId = $this->customerIdFor($store);

        if (! $customerId) {
            $customerId = $this->stripe->createCustomer(
                (string) $request->user()->email,
                $request->user()->name,
            );
            $this->memoizeCustomer($store, $customerId);
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $lineItems = [];

        if ($plan->setup_fee_cents > 0) {
            // One-time item — no `recurring`, so Checkout bills it on the first
            // invoice only (combined single checkout).
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $plan->name.' setup fee'],
                    'unit_amount' => (int) $plan->setup_fee_cents,
                ],
                'quantity' => 1,
            ];
        }

        $recurring = match ($plan->interval) {
            'weekly' => ['interval' => 'week'],
            'quarterly' => ['interval' => 'month', 'interval_count' => 3],
            'yearly' => ['interval' => 'year'],
            default => ['interval' => 'month'],
        };

        $lineItems[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => $plan->name],
                'unit_amount' => (int) $plan->price_cents,
                'recurring' => $recurring,
            ],
            'quantity' => 1,
        ];

        $url = $this->stripe->createCheckoutSession([
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $store->id,
            'line_items' => $lineItems,
            'metadata' => [
                'store_id' => (string) $store->id,
                'plan_slug' => $plan->slug,
            ],
            'success_url' => $frontend.'/admin/subscription?status=success',
            'cancel_url' => $frontend.'/admin/subscription?status=cancelled',
        ]);

        return response()->json(['url' => $url]);
    }

    /**
     * Stripe Billing Portal for current subscribers to manage/cancel their
     * plan and update their payment method.
     */
    public function portal(Request $request): JsonResponse
    {
        $store = $this->storeOf($request->user());

        if ($store === null) {
            return response()->json(['message' => 'No store on this account.'], 422);
        }

        if (! $store->hasActiveSubscription()) {
            return response()->json([
                'message' => 'An active subscription is required to open the billing portal.',
                'code' => 'subscription_required',
                'subscription_status' => $store->subscription_status,
            ], 402);
        }

        $customerId = $this->customerIdFor($store);

        if (! $customerId) {
            return response()->json(['message' => 'No payment profile found for this store.'], 422);
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $url = $this->stripe->createBillingPortalSession(
            $customerId,
            $frontend.'/admin/subscription?status=portal',
        );

        return response()->json(['url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Store $store): array
    {
        $store->load('subscriptionPlan');

        return [
            'subscription_status' => $store->subscription_status,
            'subscribed_at' => $store->subscribed_at,
            'subscription_expires_at' => $store->subscription_expires_at,
            'active' => $store->hasActiveSubscription(),
            'grace_period_days' => (int) config('subscriptions.grace_period_days'),
            'provider_customer_id' => $this->customerIdFor($store),
            'plan' => $store->subscriptionPlan
                ? [
                    'name' => $store->subscriptionPlan->name,
                    'slug' => $store->subscriptionPlan->slug,
                    'price_cents' => $store->subscriptionPlan->price_cents,
                    'setup_fee_cents' => $store->subscriptionPlan->setup_fee_cents,
                    'interval' => $store->subscriptionPlan->interval,
                ]
                : null,
        ];
    }

    private function storeOf(User $user): ?Store
    {
        $store = $user->store;

        return $store && $store->isActive() ? $store : null;
    }

    /**
     * Reuse the Stripe customer across renewals/upgrades, memoized in the most
     * recent subscription ledger row.
     */
    private function customerIdFor(Store $store): ?string
    {
        $id = null;

        if ($store->relationLoaded('subscriptions')) {
            $latest = $store->subscriptions->sortByDesc('id')->first();
            $id = $latest?->provider_customer_id;
        }

        if (! $id) {
            $id = $store->subscriptions()
                ->whereNotNull('provider_customer_id')
                ->latest('id')
                ->value('provider_customer_id');
        }

        return $id ?: null;
    }

    private function memoizeCustomer(Store $store, string $customerId): void
    {
        $store->subscriptions()->create([
            'status' => \App\Models\Subscription::STATUS_PENDING,
            'mode' => \App\Models\Subscription::MODE_INITIAL,
            'provider' => 'stripe',
            'provider_customer_id' => $customerId,
        ]);
    }
}
