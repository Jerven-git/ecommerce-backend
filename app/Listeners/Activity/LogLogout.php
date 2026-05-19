<?php

namespace App\Listeners\Activity;

use App\Models\User;
use Illuminate\Auth\Events\Logout;

class LogLogout
{
    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('logout')
            ->withProperties([
                'ip' => request()?->ip(),
                'guard' => $event->guard,
            ])
            ->log("{$event->user->email} logged out");
    }
}
