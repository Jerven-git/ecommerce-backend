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

        $this->impersonate->take($impersonator, $user);

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

        $this->impersonate->leave();

        if ($impersonator) {
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
