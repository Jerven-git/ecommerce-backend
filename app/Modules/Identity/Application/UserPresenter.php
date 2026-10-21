<?php

namespace App\Modules\Identity\Application;

use App\Models\User;

class UserPresenter
{
    public function auth(User $user): array
    {
        $user->loadMissing(['roles', 'store']);
        $roles = $user->roleNames();

        return array_merge($user->toArray(), [
            'roles' => $roles,
            'is_admin' => in_array('admin', $roles, true) || in_array('super_admin', $roles, true),
            'is_super_admin' => in_array('super_admin', $roles, true),
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'slug' => $user->store->slug,
                'domain' => $user->store->domain,
                'domain_verified' => $user->store->hasVerifiedDomain(),
                'subscription_status' => $user->store->subscription_status,
                'subscription_expires_at' => $user->store->subscription_expires_at,
            ] : null,
        ]);
    }

    public function registration(User $user): array
    {
        $data = $this->auth($user);
        if (is_array($data['store'])) {
            unset($data['store']['domain'], $data['store']['domain_verified']);
        }

        return $data;
    }

    public function administration(User $user): array
    {
        $user->loadMissing(['roles', 'store']);
        $roles = $user->roleNames();

        return array_merge($user->toArray(), [
            'roles' => $roles,
            'is_admin' => true,
            'is_super_admin' => in_array('super_admin', $roles, true),
            'role' => in_array('super_admin', $roles, true) ? 'super_admin' : 'admin',
            'store' => $this->storeSummary($user),
        ]);
    }

    public function impersonation(User $user): array
    {
        $user->loadMissing(['roles', 'store']);
        $roles = $user->roleNames();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles,
            'is_super_admin' => in_array('super_admin', $roles, true),
            'store' => $this->storeSummary($user),
        ];
    }

    private function storeSummary(User $user): ?array
    {
        return $user->store ? [
            'id' => $user->store->id,
            'name' => $user->store->name,
            'slug' => $user->store->slug,
        ] : null;
    }
}
