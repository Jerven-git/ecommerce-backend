<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
            ->with('roles')
            ->orderBy('name')
            ->orderBy('email')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->serializeUser($user))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'super_admin'])],
        ]);

        $role = Role::query()->firstOrCreate(['name' => $validated['role']]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_admin' => true,
        ]);

        $user->roles()->sync([$role->id]);
        $user->load('roles');

        return response()->json([
            'data' => $this->serializeUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isAdminLike(), 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'super_admin'])],
        ]);

        if (
            array_key_exists('role', $validated)
            && $user->isSuperAdmin()
            && $validated['role'] !== 'super_admin'
            && $this->superAdminCount() <= 1
        ) {
            return response()->json([
                'message' => 'You must keep at least one super admin account.',
            ], 422);
        }

        $user->fill(collect($validated)->except(['role', 'password'])->all());

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->is_admin = true;
        $user->save();

        if (array_key_exists('role', $validated)) {
            $role = Role::query()->firstOrCreate(['name' => $validated['role']]);
            $user->roles()->sync([$role->id]);
        }

        $user->load('roles');

        return response()->json([
            'data' => $this->serializeUser($user),
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

        if ($user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            return response()->json([
                'message' => 'You must keep at least one super admin account.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'Admin account deleted.',
        ]);
    }

    private function superAdminCount(): int
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->count();
    }

    private function serializeUser(User $user): array
    {
        $roles = $user->roleNames();

        return array_merge($user->toArray(), [
            'roles' => $roles,
            'is_admin' => in_array('admin', $roles, true) || in_array('super_admin', $roles, true),
            'is_super_admin' => in_array('super_admin', $roles, true),
            'role' => in_array('super_admin', $roles, true) ? 'super_admin' : 'admin',
        ]);
    }
}
