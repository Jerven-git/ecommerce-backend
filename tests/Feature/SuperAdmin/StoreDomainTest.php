<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\Dns\DnsLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDnsLookup;
use Tests\TestCase;

class StoreDomainTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $storeAdmin;

    protected FakeDnsLookup $dns;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('storefront.base_domain', 'shopapp.com');
        config()->set('storefront.server_ips', ['203.0.113.5']);

        $defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'store_id' => null]);
        $this->superAdmin->roles()->attach($superRole);

        $this->storeAdmin = User::factory()->create(['is_admin' => true, 'store_id' => $defaultStore->id]);
        $this->storeAdmin->roles()->attach($adminRole);

        $this->dns = new FakeDnsLookup;
        $this->app->instance(DnsLookup::class, $this->dns);
    }

    // --- Validation: what may be claimed at all -----------------------------

    /** @return array<string, array{string}> */
    public static function rejectedDomains(): array
    {
        return [
            'the platform base domain' => ['shopapp.com'],
            'a subdomain of the base domain' => ['acme.shopapp.com'],
            'a deep subdomain of the base domain' => ['a.b.shopapp.com'],
            'the www of the base domain' => ['www.shopapp.com'],
            'an IPv4 address' => ['203.0.113.5'],
            'a single label' => ['localhost'],
            'a doubled www prefix' => ['www.www.example.com'],
            'consecutive dots' => ['example..com'],
            'a leading hyphen' => ['-example.com'],
            'a numeric tld' => ['example.123'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedDomains')]
    public function test_rejects_disallowed_domain(string $domain): void
    {
        $store = Store::factory()->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['domain' => $domain])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        $this->assertNull($store->fresh()->domain);
    }

    public function test_accepts_a_normal_domain_and_stores_it_unverified(): void
    {
        $store = Store::factory()->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['domain' => 'nazareck.com'])
            ->assertOk()
            ->assertJsonPath('data.domain', 'nazareck.com')
            ->assertJsonPath('data.domain_verified', false);

        $this->assertNull($store->fresh()->domain_verified_at);
    }

    public function test_domain_is_stored_canonicalised(): void
    {
        $store = Store::factory()->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['domain' => '  WWW.Nazareck.COM '])
            ->assertOk()
            ->assertJsonPath('data.domain', 'nazareck.com');
    }

    public function test_cannot_claim_a_domain_another_store_already_holds(): void
    {
        Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'owner']);
        $other = Store::factory()->create(['slug' => 'other']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$other->id}", ['domain' => 'www.nazareck.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    public function test_changing_the_domain_resets_verification(): void
    {
        $store = Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['domain' => 'elsewhere.com'])
            ->assertOk()
            ->assertJsonPath('data.domain_verified', false);

        $this->assertNull($store->fresh()->domain_verified_at);
    }

    public function test_resaving_the_same_domain_keeps_verification(): void
    {
        $store = Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['name' => 'Renamed', 'domain' => 'WWW.nazareck.com'])
            ->assertOk()
            ->assertJsonPath('data.domain_verified', true);

        $this->assertNotNull($store->fresh()->domain_verified_at);
    }

    public function test_clearing_the_domain_clears_verification(): void
    {
        $store = Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$store->id}", ['domain' => null])
            ->assertOk()
            ->assertJsonPath('data.domain', null)
            ->assertJsonPath('data.domain_verified', false);
    }

    // --- Availability check -------------------------------------------------

    public function test_availability_reports_a_free_domain_as_available(): void
    {
        $this->dns->set('nazareck.com', ['203.0.113.5']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores/domain-availability?domain=nazareck.com')
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('dns.resolves', true)
            ->assertJsonPath('dns.points_at_server', true);
    }

    public function test_availability_reports_an_unresolved_domain_as_available_but_not_pointed(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores/domain-availability?domain=brand-new.com')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('dns.resolves', false)
            ->assertJsonPath('dns.points_at_server', false);
    }

    public function test_availability_reports_a_claimed_domain_as_taken(): void
    {
        Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'owner', 'name' => 'Nazareck']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores/domain-availability?domain=nazareck.com')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('claimed_by.name', 'Nazareck');
    }

    public function test_availability_ignores_the_stores_own_claim(): void
    {
        $store = Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'owner']);

        $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/super-admin/stores/domain-availability?domain=nazareck.com&store_id={$store->id}")
            ->assertOk()
            ->assertJsonPath('available', true);
    }

    public function test_availability_rejects_the_platform_base_domain(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/super-admin/stores/domain-availability?domain=acme.shopapp.com')
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('available', false);
    }

    public function test_store_admin_cannot_check_availability(): void
    {
        $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/super-admin/stores/domain-availability?domain=nazareck.com')
            ->assertForbidden();
    }

    // --- Verification -------------------------------------------------------

    public function test_verify_marks_the_domain_verified_when_dns_points_here(): void
    {
        $store = Store::factory()->withUnverifiedDomain('nazareck.com')->create(['slug' => 'acme']);
        $this->dns->set('nazareck.com', ['203.0.113.5']);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/domain/verify")
            ->assertOk()
            ->assertJsonPath('data.domain_verified', true);

        $this->assertNotNull($store->fresh()->domain_verified_at);
    }

    public function test_verify_fails_when_dns_points_elsewhere(): void
    {
        $store = Store::factory()->withUnverifiedDomain('nazareck.com')->create(['slug' => 'acme']);
        $this->dns->set('nazareck.com', ['198.51.100.9']);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/domain/verify")
            ->assertStatus(422)
            ->assertJsonPath('dns.resolves', true)
            ->assertJsonPath('dns.points_at_server', false);

        $this->assertNull($store->fresh()->domain_verified_at);
    }

    public function test_verify_fails_when_the_domain_does_not_resolve(): void
    {
        $store = Store::factory()->withUnverifiedDomain('nazareck.com')->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/domain/verify")
            ->assertStatus(422)
            ->assertJsonPath('dns.resolves', false);
    }

    public function test_verify_revokes_a_domain_that_stopped_pointing_here(): void
    {
        $store = Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'acme']);
        $this->dns->set('nazareck.com', ['198.51.100.9']);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/domain/verify")
            ->assertStatus(422);

        $this->assertNull($store->fresh()->domain_verified_at);
    }

    public function test_verify_refuses_when_server_ips_are_not_configured(): void
    {
        config()->set('storefront.server_ips', []);
        $store = Store::factory()->withUnverifiedDomain('nazareck.com')->create(['slug' => 'acme']);
        $this->dns->set('nazareck.com', ['203.0.113.5']);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/domain/verify")
            ->assertStatus(422);

        $this->assertNull($store->fresh()->domain_verified_at);
    }

    public function test_verify_requires_a_domain_to_be_set(): void
    {
        $store = Store::factory()->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/domain/verify")
            ->assertStatus(422);
    }

    public function test_store_admin_cannot_verify_a_domain(): void
    {
        $store = Store::factory()->withUnverifiedDomain('nazareck.com')->create(['slug' => 'acme']);

        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/domain/verify")
            ->assertForbidden();
    }

    // --- Release on delete --------------------------------------------------

    public function test_deleting_a_store_releases_its_domain_for_reuse(): void
    {
        $store = Store::factory()->withVerifiedDomain('nazareck.com')->create(['slug' => 'acme']);

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/super-admin/stores/{$store->id}")
            ->assertOk();

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'domain' => null, 'domain_verified_at' => null]);

        $successor = Store::factory()->create(['slug' => 'successor']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/super-admin/stores/{$successor->id}", ['domain' => 'nazareck.com'])
            ->assertOk()
            ->assertJsonPath('data.domain', 'nazareck.com')
            // The successor must prove control again; a stale A record left
            // behind by the previous owner must not auto-verify.
            ->assertJsonPath('data.domain_verified', false);
    }
}
