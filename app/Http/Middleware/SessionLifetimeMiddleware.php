<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SessionLifetimeMiddleware
{
    /**
     * Force logout only when BOTH conditions are true:
     * a) Session has exceeded the absolute lifetime (24h)
     * b) User has been inactive for the inactivity timeout (60min)
     *
     * Active users are NOT kicked out at the 24h mark — only once
     * they also go idle past the inactivity threshold.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $sessionCreatedAt = $request->session()->get('session_created_at');
        $lastActiveAt = $request->session()->get('last_active_at', $sessionCreatedAt);

        // Pre-existing session before this feature — start tracking now
        if (!$sessionCreatedAt) {
            $request->session()->put('session_created_at', now()->timestamp);
            $request->session()->put('last_active_at', now()->timestamp);
            return $next($request);
        }

        $maxLifetimeSeconds = (int) config('session.absolute_lifetime', 1440) * 60;
        $inactivitySeconds = (int) config('session.inactivity_timeout', 60) * 60;

        $sessionAge = now()->timestamp - $sessionCreatedAt;
        $idleTime = now()->timestamp - ($lastActiveAt ?? $sessionCreatedAt);

        if ($sessionAge >= $maxLifetimeSeconds && $idleTime >= $inactivitySeconds) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your session has expired. Please log in again.',
            ], 401);
        }

        // Update last activity on every request
        $request->session()->put('last_active_at', now()->timestamp);

        return $next($request);
    }
}
