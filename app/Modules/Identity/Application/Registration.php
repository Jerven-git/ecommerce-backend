<?php

namespace App\Modules\Identity\Application;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\StoreProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class Registration
{
    public function __construct(private readonly StoreProvisioner $stores) {}

    public function register(array $attributes): User
    {
        $this->verifyRecaptcha($attributes['recaptcha_token'] ?? null);

        return DB::transaction(function () use ($attributes): User {
            $store = $this->stores->provision([
                'name' => $attributes['store_name'],
                'slug' => $attributes['store_slug'] ?? Store::generateUniqueSlug($attributes['store_name']),
                'status' => 'active',
                'subscription_status' => Store::SUBSCRIPTION_UNSUBSCRIBED,
            ]);
            $role = Role::firstOrCreate(['name' => 'admin']);
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'is_admin' => true,
                'store_id' => $store->id,
                'status' => User::STATUS_ACTIVE,
            ]);
            $user->roles()->sync([$role->id]);

            return $user->load(['roles', 'store']);
        });
    }

    private function verifyRecaptcha(?string $token): void
    {
        $secret = config('services.recaptcha.secret_key');
        if (! $secret) {
            return;
        }
        if (! filled($token)) {
            abort(422, 'reCAPTCHA verification failed. Please try again.');
        }

        $result = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
        ])->json();

        if (! ($result['success'] ?? false)
            || ($result['score'] ?? 0) < config('services.recaptcha.threshold', 0.5)) {
            abort(422, 'reCAPTCHA verification failed. Please try again.');
        }
    }
}
