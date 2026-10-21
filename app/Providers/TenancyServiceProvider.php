<?php

namespace App\Providers;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request/job scoped so long-lived workers cannot carry one tenant's
        // context into the next unit of work.
        $this->app->scoped(CurrentStore::class);
    }

    public function boot(): void
    {
        //
    }
}
