<?php

namespace App\Modules\Realtime\Providers;

use App\Modules\Realtime\Console\NotifyDeploymentCommand;
use App\Modules\Realtime\Services\RealtimeService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/realtime.php', 'realtime');
        $this->app->singleton(RealtimeService::class);
    }

    public function boot(): void
    {
        require __DIR__ . '/../Routes/channels.php';

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                NotifyDeploymentCommand::class,
            ]);
        }
    }
}
