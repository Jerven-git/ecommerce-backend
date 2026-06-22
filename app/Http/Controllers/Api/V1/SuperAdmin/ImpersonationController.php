<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lab404\Impersonate\Services\ImpersonateManager;
use Spatie\Activitylog\Support\CauserResolver;

class ImpersonationController extends Controller
{
    public function __construct(protected ImpersonateManager $impersonate) {}

    public function start(Request $request, User $user): JsonResponse
    {
        $impersonator = $request->user();

        if (! $impersonator?->canImpersonate()) {
            return response()->json([
                'message' => 'You are not allowed to impersonate.',
            ], 403);
        }

        if ((int) $impersonator->id === (int) $user->id) {
            return response()->json([
                'message' => 'You cannot impersonate yourself.',
            ], 422);
        }

        if (! $user->canBeImpersonated()) {
            return response()->json([
                'message' => 'This user cannot be impersonated.',
            ], 422);
        }

        if ($this->impersonate->isImpersonating()) {
            return response()->json([
                'message' => 'You are already impersonating another user. Leave first.',
            ], 422);
        }

        \Log::channel('single')->info('IMP_DEBUG start:before-take', [
            'session_id' => session()->getId(),
            'session_keys' => array_keys(session()->all()),
        ]);

        $takeResult = $this->impersonate->take($impersonator, $user);

        \Log::channel('single')->info('IMP_DEBUG take_result', ['ok' => $takeResult]);

        // Diagnostic: surface the exception lab404 swallows in take()'s try/catch.
        try {
            \Illuminate\Support\Facades\Auth::guard('web')->quietLogin($user);
            \Log::channel('single')->info('IMP_DEBUG manual_quietLogin_ok', [
                'web_user_id' => optional(\Illuminate\Support\Facades\Auth::guard('web')->user())->id,
            ]);
        } catch (\Throwable $e) {
            \Log::channel('single')->error('IMP_DEBUG manual_quietLogin_FAILED', [
                'class' => get_class($e),
                'msg' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);
        }

        $this->syncSessionPasswordHash($user);

        \Log::channel('single')->info('IMP_DEBUG start:after-take', [
            'session_id' => session()->getId(),
            'session_keys' => array_keys(session()->all()),
            'is_impersonating' => $this->impersonate->isImpersonating(),
            'web_guard_id' => optional(\Illuminate\Support\Facades\Auth::guard('web')->user())->id,
            'request_session_same' => session()->getId() === request()->session()->getId(),
            'session_cookie_name' => config('session.cookie'),
            'session_domain' => config('session.domain'),
            'session_same_site' => config('session.same_site'),
            'session_secure' => config('session.secure'),
        ]);

        activity('impersonation')
            ->causedBy($impersonator)
            ->performedOn($user)
            ->withProperties([
                'impersonator_id' => $impersonator->id,
                'impersonator_email' => $impersonator->email,
                'target_id' => $user->id,
                'target_email' => $user->email,
            ])
            ->event('impersonation_started')
            ->log("Super admin {$impersonator->email} started impersonating {$user->email}");

        $user->load(['roles', 'store']);

        return response()->json([
            'message' => 'Impersonation started.',
            'data' => [
                'user' => $this->serializeUser($user),
                'impersonator' => $this->serializeUser($impersonator->fresh()->load(['roles', 'store'])),
            ],
        ]);
    }

    public function leave(): JsonResponse
    {
        if (! $this->impersonate->isImpersonating()) {
            return response()->json([
                'message' => 'You are not impersonating anyone.',
            ], 422);
        }

        $impersonated = Auth::user();
        $impersonatorId = session(config('laravel-impersonate.session_key'));
        $impersonator = User::find($impersonatorId);

        $this->impersonate->leave();

        if ($impersonator) {
            $this->syncSessionPasswordHash($impersonator);

            app(CauserResolver::class)->setCauser($impersonator);

            activity('impersonation')
                ->causedBy($impersonator)
                ->performedOn($impersonated)
                ->withProperties([
                    'impersonator_id' => $impersonator->id,
                    'impersonator_email' => $impersonator->email,
                    'target_id' => $impersonated?->id,
                    'target_email' => $impersonated?->email,
                ])
                ->event('impersonation_stopped')
                ->log("Super admin {$impersonator->email} stopped impersonating {$impersonated?->email}");
        }

        return response()->json([
            'message' => 'Impersonation ended.',
            'data' => [
                'user' => $impersonator ? $this->serializeUser($impersonator->fresh()->load(['roles', 'store'])) : null,
            ],
        ]);
    }

    /**
     * Re-stamp the session's password-hash fingerprint for the now-effective
     * user.
     *
     * Impersonation swaps the authenticated user on the session but leaves the
     * original user's fingerprint (stored by Laravel's AuthenticateSession
     * middleware, which Sanctum enables for SPA requests) untouched. On the
     * very next request the middleware sees the hash no longer matches the
     * effective user, flushes the session and silently logs everyone out.
     * Re-stamping the fingerprint keeps the protection intact while letting the
     * swap survive into subsequent requests.
     */
    private function syncSessionPasswordHash(User $user): void
    {
        $driver = Auth::getDefaultDriver();
        $guard = Auth::guard($driver);

        if (! method_exists($guard, 'hashPasswordForCookie')) {
            return;
        }

        session()->put(
            'password_hash_'.$driver,
            $guard->hashPasswordForCookie($user->getAuthPassword()),
        );
    }

    private function serializeUser(User $user): array
    {
        $roles = $user->roleNames();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles,
            'is_super_admin' => in_array('super_admin', $roles, true),
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'slug' => $user->store->slug,
            ] : null,
        ];
    }
}
