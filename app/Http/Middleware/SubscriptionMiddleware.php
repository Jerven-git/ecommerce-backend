<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionMiddleware
{
    /**
     * Gate store-management access behind an active (or Super-Admin-granted)
     * subscription. Gating is at the tenant/store level: a store owner and any
     * additional staff who share the store's `store_id` all inherit the same
     * status. Users who can still log in but belong to a gated store receive a
     * clear `subscription_required` error so the UI can route them to checkout.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Super admins operate platform-wide and are never subject to a tenant's
        // subscription.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $store = $user->store;

        // Admins without a store (legacy edge) have no tenant to gate.
        if ($store === null) {
            return $next($request);
        }

        // While a Super Admin impersonates a store owner, they should be able to
        // set the store up before it is paid for, so gating is bypassed.
        if ($request->hasSession() && $this->isImpersonatedBySuperAdmin($request)) {
            return $next($request);
        }

        if ($store->hasActiveSubscription()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'A subscription is required to manage this store.',
            'code' => 'subscription_required',
            'subscription_status' => $store->subscription_status,
        ], 402);
    }

    private function isImpersonatedBySuperAdmin(Request $request): bool
    {
        // Requests handled without a session (e.g. stateless feature tests or
        // CLI) can't be "impersonating", so the session may be absent.
        if (! $request->hasSession()) {
            return false;
        }

        $impersonatorId = $request->session()->get(config('laravel-impersonate.session_key'));

        if (! $impersonatorId) {
            return false;
        }

        $impersonator = User::query()->find($impersonatorId);

        return $impersonator !== null && $impersonator->isSuperAdmin();
    }
}
