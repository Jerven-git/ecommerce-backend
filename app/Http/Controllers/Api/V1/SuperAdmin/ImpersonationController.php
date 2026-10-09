<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lab404\Impersonate\Services\ImpersonateManager;
use Spatie\Activitylog\Support\CauserResolver;
use Throwable;

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

        $guardName = $this->impersonate->getCurrentAuthGuardName()
            ?? $this->impersonate->getDefaultSessionGuard();

        try {
            $takeResult = $this->impersonate->take($impersonator, $user, $guardName);

            if (! $takeResult
                || ! $this->impersonate->isImpersonating()
                || (int) Auth::guard($guardName)->id() !== (int) $user->id) {
                throw new \RuntimeException('The impersonation session could not be established.');
            }

            $this->syncSessionPasswordHash($user, $guardName);

            $targetData = $this->serializeUser($user->load(['roles', 'store']));
            $impersonatorData = $this->serializeUser($impersonator->fresh()->load(['roles', 'store']));

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
        } catch (Throwable $exception) {
            $restored = $this->restoreImpersonator($impersonator, $guardName);

            if (! $restored) {
                $this->terminateImpersonationSession($request, $guardName);
            }

            try {
                report($exception);
            } catch (Throwable) {
                // Session recovery must not be undone by a failing log sink.
            }

            return response()->json([
                'message' => $restored
                    ? 'Unable to start impersonation. Your session was restored.'
                    : 'Unable to start impersonation. Please sign in again.',
                'code' => 'impersonation_start_failed',
            ], 500);
        }

        return response()->json([
            'message' => 'Impersonation started.',
            'data' => [
                'user' => $targetData,
                'impersonator' => $impersonatorData,
            ],
        ]);
    }

    public function leave(Request $request): JsonResponse
    {
        if (! $this->impersonate->isImpersonating()) {
            return response()->json([
                'message' => 'You are not impersonating anyone.',
            ], 422);
        }

        $impersonated = Auth::user();
        $impersonatorId = session(config('laravel-impersonate.session_key'));
        $impersonator = User::find($impersonatorId);
        $impersonatorGuard = $this->impersonate->getImpersonatorGuardName()
            ?? $this->impersonate->getDefaultSessionGuard();

        if (! $impersonator?->canImpersonate()) {
            $this->terminateImpersonationSession($request, $impersonatorGuard);

            return response()->json([
                'message' => 'The originating account is no longer available. Please sign in again.',
                'code' => 'impersonation_source_unavailable',
            ], 401);
        }

        $left = $this->impersonate->leave();

        if (! $left
            || $this->impersonate->isImpersonating()
            || (int) Auth::guard($impersonatorGuard)->id() !== (int) $impersonator->id) {
            if (! $this->restoreImpersonator($impersonator, $impersonatorGuard)) {
                $this->terminateImpersonationSession($request, $impersonatorGuard);

                return response()->json([
                    'message' => 'Unable to restore the originating account. Please sign in again.',
                    'code' => 'impersonation_leave_failed',
                ], 500);
            }
        }

        $this->syncSessionPasswordHash($impersonator, $impersonatorGuard);

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

        return response()->json([
            'message' => 'Impersonation ended.',
            'data' => [
                'user' => $this->serializeUser($impersonator->fresh()->load(['roles', 'store'])),
            ],
        ]);
    }

    private function restoreImpersonator(User $impersonator, string $guardName): bool
    {
        try {
            $this->impersonate->clear();
            Auth::guard($guardName)->quietLogin($impersonator);
            $this->syncSessionPasswordHash($impersonator, $guardName);

            return (int) Auth::guard($guardName)->id() === (int) $impersonator->id;
        } catch (Throwable) {
            return false;
        }
    }

    private function terminateImpersonationSession(Request $request, string $guardName): void
    {
        $this->impersonate->clear();
        Auth::guard($guardName)->quietLogout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
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
    private function syncSessionPasswordHash(User $user, ?string $driver = null): void
    {
        $driver ??= Auth::getDefaultDriver();
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
