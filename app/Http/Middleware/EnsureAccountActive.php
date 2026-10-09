<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    /**
     * Reject an authenticated account as soon as it is disabled without
     * changing any existing role, tenant, or subscription authorization.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Revoked users must still be able to exit the current identity and
        // invalidate or restore their server session.
        if ($request->is('api/v1/logout', 'api/v1/super-admin/impersonate/leave')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user?->isDisabled()) {
            return response()->json([
                'message' => 'Your account has been disabled. Contact a super admin.',
                'code' => 'account_disabled',
            ], 403);
        }

        return $next($request);
    }
}
