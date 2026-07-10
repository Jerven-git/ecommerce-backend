<?php

namespace Tests\Feature\Tenancy;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CanonicalHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('storefront.base_domain', 'shopapp.com');
        app(CurrentStore::class)->clear();
    }

    // --- Subdomain -> verified custom domain --------------------------------

    public function test_subdomain_redirects_to_a_verified_custom_domain(): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('jervenstore.shopapp.com')
            ->assertUnauthorized()
            ->assertHeader('X-Canonical-Redirect', 'http://jervenstore.com');
    }

    public function test_subdomain_does_not_redirect_to_an_unverified_domain(): void
    {
        // The claim isn't proven yet, so the subdomain remains canonical.
        Store::factory()->withUnverifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('jervenstore.shopapp.com')
            ->assertNoContent()
            ->assertHeaderMissing('X-Canonical-Redirect');
    }

    public function test_subdomain_without_a_custom_domain_does_not_redirect(): void
    {
        Store::factory()->create(['slug' => 'acme']);

        $this->check('acme.shopapp.com')->assertNoContent()->assertHeaderMissing('X-Canonical-Redirect');
    }

    public function test_inactive_store_does_not_redirect(): void
    {
        Store::factory()->inactive()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('jervenstore.shopapp.com')->assertNoContent()->assertHeaderMissing('X-Canonical-Redirect');
    }

    public function test_unknown_subdomain_does_not_redirect(): void
    {
        $this->check('nobody.shopapp.com')->assertNoContent()->assertHeaderMissing('X-Canonical-Redirect');
    }

    // --- www -> bare --------------------------------------------------------

    public function test_www_of_a_custom_domain_redirects_to_the_bare_domain(): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('www.jervenstore.com')
            ->assertUnauthorized()
            ->assertHeader('X-Canonical-Redirect', 'http://jervenstore.com');
    }

    public function test_bare_custom_domain_does_not_redirect(): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('jervenstore.com')->assertNoContent()->assertHeaderMissing('X-Canonical-Redirect');
    }

    public function test_www_of_a_subdomain_redirects_to_the_bare_subdomain(): void
    {
        Store::factory()->create(['slug' => 'acme']);

        $this->check('www.acme.shopapp.com')
            ->assertUnauthorized()
            ->assertHeader('X-Canonical-Redirect', 'http://acme.shopapp.com');
    }

    public function test_www_of_the_apex_redirects_to_the_bare_apex(): void
    {
        $this->check('www.shopapp.com')
            ->assertUnauthorized()
            ->assertHeader('X-Canonical-Redirect', 'http://shopapp.com');
    }

    public function test_bare_apex_does_not_redirect(): void
    {
        $this->check('shopapp.com')->assertNoContent()->assertHeaderMissing('X-Canonical-Redirect');
    }

    // --- Hosts bound to nothing ---------------------------------------------

    public function test_unbound_host_is_left_alone(): void
    {
        $this->check('someone-elses-domain.com')->assertNoContent()->assertHeaderMissing('X-Canonical-Redirect');
    }

    public function test_empty_host_is_left_alone(): void
    {
        $this->getJson('/api/v1/canonical-host')->assertNoContent();
    }

    // --- Exempt paths --------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function exemptPaths(): array
    {
        return [
            // Admins sign in from any host and their session cookie is scoped to
            // the base domain; redirecting them would drop it.
            'admin login' => ['/admin/login'],
            'super admin' => ['/super-admin/stores'],
            'api' => ['/api/v1/products'],
            'storage' => ['/storage/media/x.png'],
            'websocket' => ['/app/reverb'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exemptPaths')]
    public function test_exempt_paths_are_never_redirected(string $path): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('jervenstore.shopapp.com', $path)->assertNoContent()->assertHeaderMissing('X-Canonical-Redirect');
    }

    public function test_a_path_merely_prefixed_like_an_exempt_one_still_redirects(): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('jervenstore.shopapp.com', '/administrators-choice')
            ->assertUnauthorized()
            ->assertHeader('X-Canonical-Redirect', 'http://jervenstore.com');
    }

    public function test_query_string_in_the_original_uri_does_not_confuse_path_matching(): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->check('jervenstore.shopapp.com', '/products?sort=price&admin=1')
            ->assertUnauthorized()
            ->assertHeader('X-Canonical-Redirect', 'http://jervenstore.com');
    }

    // --- Scheme --------------------------------------------------------------

    public function test_redirect_uses_https_when_the_edge_forwarded_https(): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->getJson('/api/v1/canonical-host?host=jervenstore.shopapp.com', [
            'X-Original-URI' => '/',
            'X-Forwarded-Proto' => 'https',
        ])->assertUnauthorized()->assertHeader('X-Canonical-Redirect', 'https://jervenstore.com');
    }

    // --- SPA fallback source -------------------------------------------------

    public function test_site_config_exposes_the_canonical_host_for_the_spa_fallback(): void
    {
        Store::factory()->withVerifiedDomain('jervenstore.com')->create(['slug' => 'jervenstore']);

        $this->getJson('http://jervenstore.shopapp.com/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.canonical_host', 'jervenstore.com');
    }

    public function test_site_config_reports_the_subdomain_as_canonical_without_a_custom_domain(): void
    {
        Store::factory()->create(['slug' => 'acme']);

        $this->getJson('http://acme.shopapp.com/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.canonical_host', 'acme.shopapp.com');
    }

    protected function check(string $host, string $originalUri = '/'): TestResponse
    {
        return $this->getJson(
            '/api/v1/canonical-host?host='.urlencode($host),
            ['X-Original-URI' => $originalUri]
        );
    }
}
