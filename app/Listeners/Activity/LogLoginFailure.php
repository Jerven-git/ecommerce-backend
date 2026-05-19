<?php

namespace App\Listeners\Activity;

use App\Models\User;
use Illuminate\Auth\Events\Failed;

class LogLoginFailure
{
    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $existingUser = $email ? User::query()->where('email', strtolower(trim($email)))->first() : null;

        activity('auth')
            ->when($existingUser, fn ($log) => $log->causedBy($existingUser)->performedOn($existingUser))
            ->event('login_failed')
            ->withProperties([
                'email' => $email,
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'guard' => $event->guard,
                'user_exists' => $existingUser !== null,
            ])
            ->log("Failed login attempt for {$email}");
    }
}
