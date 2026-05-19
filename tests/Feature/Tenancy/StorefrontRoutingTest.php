<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveStorefrontStore;
use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class StorefrontRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $defaultStore;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('storefront.base_domain', 'localhost');
        config()->set('storefront.default_store_slug', Store::DEFAULT_SLUG);

        $this->defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        // The base TestCase pre-sets CurrentStore to the default store; clear
        // it so we can assert what the middleware *itself* does.
        app(CurrentStore::class)->clear();
    }

    public function test_subdomain_resolves_to_matching_store(): void
    {
        $store = Store::factory()->create(['slug' => 'acme']);

        $this->runMiddleware('acme.localhost');

        $this->assertSame($store->id, app(CurrentStore::class)->id());
    }

    public function test_bare_base_domain_falls_back_to_default_store(): void
    {
        $this->runMiddleware('localhost');

        $this->assertSame($this->defaultStore->id, app(CurrentStore::class)->id());
    }

    public function test_unknown_subdomain_returns_404(): void
    {
        $response = $this->runMiddleware('nope.localhost');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse(app(CurrentStore::class)->isSet());
    }

    public function test_inactive_store_returns_404(): void
    {
        Store::factory()->inactive()->create(['slug' => 'paused']);

        $response = $this->runMiddleware('paused.localhost');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse(app(CurrentStore::class)->isSet());
    }

    public function test_host_outside_configured_base_domain_falls_back_to_default(): void
    {
        $this->runMiddleware('203.0.113.5');

        $this->assertSame($this->defaultStore->id, app(CurrentStore::class)->id());
    }

    public function test_middleware_is_wired_to_public_storefront_routes(): void
    {
        // End-to-end smoke: hitting a public storefront route on an unknown
        // subdomain must 404, proving the middleware is in the route group.
        $this->getJson('http://unknown.localhost/api/v1/products')
            ->assertStatus(404);
    }

    public function test_webhook_routes_bypass_storefront_resolution(): void
    {
        // Webhooks come from external providers and shouldn't be tied to a
        // store subdomain. They must not 404 on an unknown host (they may
        // return a different error from the provider handler, just not the
        // storefront 404).
        $response = $this->postJson('http://totally-bogus.localhost/api/v1/webhooks/stripe', []);

        $this->assertNotSame(404, $response->getStatusCode());
    }

    /**
     * Run the middleware against a request with the given host and return the
     * resulting response. Caller asserts on CurrentStore state.
     */
    protected function runMiddleware(string $host): \Symfony\Component\HttpFoundation\Response
    {
        $middleware = app(ResolveStorefrontStore::class);
        $request = Request::create('http://'.$host.'/api/v1/products');

        return $middleware->handle($request, fn () => response('ok'));
    }
}
