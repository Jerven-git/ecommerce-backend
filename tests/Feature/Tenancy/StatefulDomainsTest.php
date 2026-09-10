<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatefulDomainsTest extends TestCase
{
    // Admins log in from a store's dynamically created subdomain/custom domain,
    // none of which are listed in SANCTUM_STATEFUL_DOMAINS. The current request
    // host placeholder must keep those requests stateful so the 2FA session survives.

    private function setLiveStatefulDomains(string $domains): void
    {
        config()->set('sanctum.stateful', explode(',', $domains.Sanctum::currentRequestHost()));
    }

    public function test_custom_store_domain_is_stateful_even_when_not_listed_in_env(): void
    {
        $this->setLiveStatefulDomains('shopapp.com,www.shopapp.com');

        $request = Request::create('http://nazareck.com/api/v1/login', 'POST');
        $request->headers->set('Origin', 'http://nazareck.com');

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function test_store_subdomain_is_stateful_even_when_not_listed_in_env(): void
    {
        $this->setLiveStatefulDomains('shopapp.com,www.shopapp.com');

        $request = Request::create('http://acme.shopapp.com/api/v1/login', 'POST');
        $request->headers->set('Origin', 'http://acme.shopapp.com');

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function test_request_whose_origin_does_not_match_the_host_is_not_stateful(): void
    {
        $this->setLiveStatefulDomains('shopapp.com,www.shopapp.com');

        $request = Request::create('http://nazareck.com/api/v1/login', 'POST');
        $request->headers->set('Origin', 'http://evil.example.com');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function test_request_without_an_origin_is_not_stateful(): void
    {
        $this->setLiveStatefulDomains('shopapp.com,www.shopapp.com');

        $request = Request::create('http://nazareck.com/api/v1/login', 'POST');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function test_config_always_appends_the_current_request_host_placeholder(): void
    {
        $this->assertContains(Sanctum::$currentRequestHostPlaceholder, config('sanctum.stateful', []));
    }
}
