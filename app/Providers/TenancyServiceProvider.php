<?php

namespace App\Providers;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentStore::class);
    }

    public function boot(): void
    {
        //
    }
}
