<?php

namespace Tests\Feature\Tenancy;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TlsCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('storefront.base_domain', 'shopapp.com');
        app(CurrentStore::class)->clear();
    }

    public function test_issues_for_active_custom_domain(): void
    {
        Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'nazareck', 'status' => 'active']);

        $this->getJson('/api/v1/tls-check?domain=nazareck.com')->assertOk();
    }

    public function test_issues_for_active_store_subdomain(): void
    {
        Store::factory()->create(['slug' => 'acme', 'status' => 'active']);

        $this->getJson('/api/v1/tls-check?domain=acme.shopapp.com')->assertOk();
    }

    public function test_issues_for_app_apex_and_www(): void
    {
        $this->getJson('/api/v1/tls-check?domain=shopapp.com')->assertOk();
        $this->getJson('/api/v1/tls-check?domain=www.shopapp.com')->assertOk();
    }

    public function test_issues_for_www_of_active_custom_domain(): void
    {
        Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'nazareck', 'status' => 'active']);

        $this->getJson('/api/v1/tls-check?domain=www.nazareck.com')->assertOk();
    }

    public function test_rejects_unknown_custom_domain(): void
    {
        $this->getJson('/api/v1/tls-check?domain=evil.com')->assertStatus(403);
    }

    public function test_rejects_unknown_subdomain(): void
    {
        $this->getJson('/api/v1/tls-check?domain=nope.shopapp.com')->assertStatus(403);
    }

    public function test_rejects_inactive_store_domain(): void
    {
        Store::factory()->inactive()->withVerifiedDomain('paused.com')->create(['slug' => 'paused']);

        $this->getJson('/api/v1/tls-check?domain=paused.com')->assertStatus(403);
        $this->getJson('/api/v1/tls-check?domain=paused.shopapp.com')->assertStatus(403);
    }

    public function test_rejects_unverified_custom_domain(): void
    {
        // An unverified claim must not earn a certificate, or a typo'd domain
        // would burn Let's Encrypt's failed-validation quota on every request.
        Store::factory()->withUnverifiedDomain('nazareck.com')->create(['slug' => 'nazareck', 'status' => 'active']);

        $this->getJson('/api/v1/tls-check?domain=nazareck.com')->assertStatus(403);
    }

    public function test_domain_row_pointing_at_a_platform_subdomain_does_not_make_it_issuable(): void
    {
        // The host is under the base domain, so only a real slug can authorise
        // it — a custom-domain row claiming it is ignored.
        Store::factory()->withVerifiedDomain('ghost.shopapp.com')->create(['slug' => 'impostor', 'status' => 'active']);

        $this->getJson('/api/v1/tls-check?domain=ghost.shopapp.com')->assertStatus(403);
    }

    public function test_rejects_empty_domain(): void
    {
        $this->getJson('/api/v1/tls-check')->assertStatus(403);
    }
}
