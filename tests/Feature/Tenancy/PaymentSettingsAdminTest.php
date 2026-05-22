<?php

namespace Tests\Feature\Tenancy;

use App\Models\PaymentSetting;
use App\Models\Role;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Store $storeA;

    protected Store $storeB;

    protected User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
        $this->storeB = Store::factory()->create(['name' => 'Watch World']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->adminB = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $this->adminB->roles()->attach($adminRole);

        app(CurrentStore::class)->clear();
    }

    public function test_admin_can_save_gateway_credentials(): void
    {
        $this->actingAs($this->adminB)
            ->patchJson('/api/v1/payment-settings', [
                'stripe_enabled' => true,
                'stripe_publishable_key' => 'pk_live_b',
                'stripe_secret_key' => 'sk_live_b',
            ])
            ->assertOk()
            ->assertJsonPath('data.stripe_publishable_key', 'pk_live_b')
            ->assertJsonPath('data.stripe_secret_key_configured', true);

        $stored = PaymentSetting::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $this->storeB->id)->first();
        $this->assertSame('sk_live_b', $stored->stripe_secret_key);

        // Encrypted at rest.
        $raw = \DB::table('payment_settings')->where('store_id', $this->storeB->id)->value('stripe_secret_key');
        $this->assertStringNotContainsString('sk_live_b', (string) $raw);
    }

    public function test_blank_secret_does_not_overwrite_existing(): void
    {
        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create(['stripe_enabled' => true, 'stripe_secret_key' => 'sk_original']);
        app(CurrentStore::class)->clear();

        $this->actingAs($this->adminB)
            ->patchJson('/api/v1/payment-settings', [
                'stripe_publishable_key' => 'pk_updated',
                'stripe_secret_key' => '', // blank — must not wipe
            ])
            ->assertOk();

        $stored = PaymentSetting::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $this->storeB->id)->first();
        $this->assertSame('sk_original', $stored->stripe_secret_key);
        $this->assertSame('pk_updated', $stored->stripe_publishable_key);
    }

    public function test_show_never_returns_raw_secrets(): void
    {
        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create([
            'stripe_enabled' => true,
            'stripe_publishable_key' => 'pk_b',
            'stripe_secret_key' => 'sk_secret_b',
        ]);
        app(CurrentStore::class)->clear();

        $response = $this->actingAs($this->adminB)->getJson('/api/v1/payment-settings')->assertOk();

        $response->assertJsonPath('data.stripe_publishable_key', 'pk_b');
        $response->assertJsonPath('data.stripe_secret_key_configured', true);
        $response->assertJsonMissingPath('data.stripe_secret_key');
        $this->assertStringNotContainsString('sk_secret_b', $response->getContent());
    }

    public function test_update_is_scoped_to_admins_store(): void
    {
        // Default store has its own settings.
        app(CurrentStore::class)->set($this->storeA);
        PaymentSetting::create(['stripe_publishable_key' => 'pk_default']);
        app(CurrentStore::class)->clear();

        $this->actingAs($this->adminB)
            ->patchJson('/api/v1/payment-settings', ['stripe_publishable_key' => 'pk_b'])
            ->assertOk();

        $default = PaymentSetting::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $this->storeA->id)->first();
        $this->assertSame('pk_default', $default->stripe_publishable_key);
    }
}
