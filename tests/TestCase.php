<?php

namespace Tests;

use App\Models\SiteConfig;
use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteDocumentStore;
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

    /** @return array<string, mixed> */
    protected function websiteDocument(SiteConfig $config): array
    {
        return HomepageSnapshot::fromConfig($config->fresh());
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    protected function updateWebsiteDocument(SiteConfig $config, array $changes): array
    {
        app(WebsiteDocumentStore::class)->write(
            $config,
            array_replace(HomepageSnapshot::fromConfig($config->fresh()), $changes),
        );
        $config->touch();

        return $this->websiteDocument($config);
    }
}
