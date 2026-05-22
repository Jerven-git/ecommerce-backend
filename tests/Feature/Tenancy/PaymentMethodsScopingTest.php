<?php

namespace Tests\Feature\Tenancy;

use App\Models\PaymentSetting;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodsScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $defaultStore;

    protected Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
        $this->otherStore = Store::factory()->create(['name' => 'Watch World']);
    }

    public function test_public_endpoint_returns_host_stores_publishable_key(): void
    {
        // Host in tests resolves to the default store.
        app(CurrentStore::class)->set($this->defaultStore);
        PaymentSetting::create([
            'stripe_enabled' => true,
            'stripe_publishable_key' => 'pk_default',
            'stripe_secret_key' => 'sk_default_secret',
        ]);
        app(CurrentStore::class)->clear();

        $response = $this->getJson('/api/v1/payment-settings/methods')->assertOk();

        $stripe = collect($response->json('data'))->firstWhere('id', 'stripe');
        $this->assertNotNull($stripe);
        $this->assertSame('pk_default', $stripe['config']['publishable_key']);
    }

    public function test_admin_sees_their_own_stores_publishable_key(): void
    {
        app(CurrentStore::class)->set($this->defaultStore);
        PaymentSetting::create(['stripe_enabled' => true, 'stripe_publishable_key' => 'pk_default']);

        app(CurrentStore::class)->set($this->otherStore);
        PaymentSetting::create(['stripe_enabled' => true, 'stripe_publishable_key' => 'pk_other']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminB = User::factory()->create(['is_admin' => true, 'store_id' => $this->otherStore->id]);
        $adminB->roles()->attach($adminRole);

        app(CurrentStore::class)->clear();

        $response = $this->actingAs($adminB)->getJson('/api/v1/payment-settings/methods')->assertOk();

        $stripe = collect($response->json('data'))->firstWhere('id', 'stripe');
        $this->assertSame('pk_other', $stripe['config']['publishable_key']);
    }

    public function test_secrets_never_appear_in_the_methods_response(): void
    {
        app(CurrentStore::class)->set($this->defaultStore);
        PaymentSetting::create([
            'stripe_enabled' => true,
            'stripe_publishable_key' => 'pk_default',
            'stripe_secret_key' => 'sk_top_secret',
            'stripe_webhook_secret' => 'whsec_top_secret',
        ]);
        app(CurrentStore::class)->clear();

        $body = $this->getJson('/api/v1/payment-settings/methods')->assertOk()->getContent();

        $this->assertStringNotContainsString('sk_top_secret', $body);
        $this->assertStringNotContainsString('whsec_top_secret', $body);
    }
}
