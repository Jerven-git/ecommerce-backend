<?php

namespace App\Listeners\Activity;

use App\Models\User;
use Illuminate\Auth\Events\Login;

class LogLoginSuccess
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('login')
            ->withProperties([
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'guard' => $event->guard,
            ])
            ->log("{$event->user->email} logged in");
    }
}
