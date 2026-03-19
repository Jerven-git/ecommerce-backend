<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    \App\Models\TwoFactorCode::where('expires_at', '<', now()->subHour())->delete();
})->hourly();

Schedule::command('payments:expire-stale --minutes=30')->everyFifteenMinutes();

Schedule::command('backorders:expire-tokens')->everyFifteenMinutes();
