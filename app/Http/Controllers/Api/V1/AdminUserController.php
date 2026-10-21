<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\AdminUserManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function __construct(private readonly AdminUserManager $users) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->users->paginate($validated));
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $this->users->show($user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'super_admin'])],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'store_name' => ['nullable', 'string', 'max:255'],
            'store_slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:stores,slug'],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        // An admin must land in a store: either an existing one (store_id) or a
        // freshly provisioned one (store_name). Super admins never get a store.
        if ($validated['role'] === 'admin' && empty($validated['store_id']) && empty($validated['store_name'])) {
            return response()->json([
                'message' => 'Select an existing store to assign this admin to, or provide a name for a new store.',
            ], 422);
        }

        $user = $this->users->create($validated);

        return response()->json(['data' => $this->users->present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isAdminLike(), 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'store_id' => ['sometimes', 'required', 'integer', Rule::exists('stores', 'id')],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        if ($request->has('role')) {
            return response()->json([
                'message' => 'Role changes are not allowed. Delete the account and create a new one with the desired role.',
            ], 422);
        }

        if ($request->has('store_id') && $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Super admins operate across all stores and cannot be assigned to one.',
            ], 422);
        }

        if (
            array_key_exists('status', $validated)
            && $validated['status'] === User::STATUS_DISABLED
            && $user->isSuperAdmin()
            && $this->users->superAdminCount(activeOnly: true) <= 1
        ) {
            return response()->json([
                'message' => 'You must keep at least one active super admin account.',
            ], 422);
        }

        if (
            array_key_exists('status', $validated)
            && $validated['status'] === User::STATUS_DISABLED
            && (int) $request->user()->id === (int) $user->id
        ) {
            return response()->json([
                'message' => 'You cannot disable your own account.',
            ], 422);
        }

        $user = $this->users->update($user, $validated);

        return response()->json([
            'data' => $this->users->present($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isAdminLike(), 404);

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($user->isSuperAdmin() && $this->users->superAdminCount() <= 1) {
            return response()->json([
                'message' => 'You must keep at least one super admin account.',
            ], 422);
        }

        $storeDeleted = $this->users->delete($user);

        return response()->json([
            'message' => $storeDeleted
                ? 'Admin account deleted. Its store had no other admins and was removed.'
                : 'Admin account deleted.',
        ]);
    }
}
