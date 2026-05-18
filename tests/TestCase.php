<?php

namespace Tests;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $defaultStore = Store::query()->where('slug', Store::DEFAULT_SLUG)->first();
        } catch (\Throwable) {
            return;
        }

        if ($defaultStore) {
            app(CurrentStore::class)->set($defaultStore);
        }
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            app(CurrentStore::class)->clear();
        }

        parent::tearDown();
    }
}
