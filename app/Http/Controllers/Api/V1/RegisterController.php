<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RegisterController extends Controller
{
    /**
     * Self-service signup: a prospect creates their account plus a brand-new
     * store in one atomic step, which is created gated (`unsubscribed`) until
     * they complete checkout. No auto-login — the platform's mandatory 2FA
     * login is a separate two-step flow (see §8.2 of SUBSCRIPTION_FEATURE.md).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->verifyRecaptcha($validated['recaptcha_token'] ?? null);

        $user = DB::transaction(function () use ($validated): User {
            $store = Store::create([
                'name' => $validated['store_name'],
                'slug' => $validated['store_slug'] ?? Store::generateUniqueSlug($validated['store_name']),
                'status' => 'active',
                // New self-service stores start gated; the column default would
                // do this anyway, but being explicit keeps the contract visible.
                'subscription_status' => Store::SUBSCRIPTION_UNSUBSCRIBED,
            ]);

            SiteConfig::withoutGlobalScope(StoreScope::class)->create([
                'store_id' => $store->id,
            ]);

            $role = Role::query()->firstOrCreate(['name' => 'admin']);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_admin' => true,
                'store_id' => $store->id,
                'status' => User::STATUS_ACTIVE,
            ]);

            $user->roles()->sync([$role->id]);

            return $user;
        });

        $user->load(['roles', 'store']);

        return response()->json([
            'message' => 'Account created. Log in to finish setting up your store.',
            'data' => $this->serializeUser($user),
            'redirect' => '/subscribe',
        ], 201);
    }

    private function serializeUser(User $user): array
    {
        $roles = $user->roleNames();

        return array_merge($user->toArray(), [
            'roles' => $roles,
            'is_admin' => in_array('admin', $roles, true) || in_array('super_admin', $roles, true),
            'is_super_admin' => in_array('super_admin', $roles, true),
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'slug' => $user->store->slug,
                'subscription_status' => $user->store->subscription_status,
                'subscription_expires_at' => $user->store->subscription_expires_at,
            ] : null,
        ]);
    }

    private function verifyRecaptcha(?string $token): void
    {
        $secretKey = config('services.recaptcha.secret_key');

        // reCAPTCHA is optional; validation only happens when a key is set.
        if (! $secretKey) {
            return;
        }

        if ($token === null || $token === '') {
            abort(422, 'reCAPTCHA verification failed. Please try again.');
        }

        $threshold = config('services.recaptcha.threshold', 0.5);

        /** @var Response $response */
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secretKey,
            'response' => $token,
        ]);

        $result = $response->json();

        if (! ($result['success'] ?? false) || ($result['score'] ?? 0) < $threshold) {
            abort(422, 'reCAPTCHA verification failed. Please try again.');
        }
    }
}
