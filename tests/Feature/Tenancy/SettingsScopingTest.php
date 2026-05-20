<?php

namespace Tests\Feature\Tenancy;

use App\Models\PaymentSetting;
use App\Models\Role;
use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\TaxRule;
use App\Models\TaxSetting;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Store $storeA;

    protected Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $this->storeB = Store::factory()->create(['name' => 'Watch World']);
    }

    public function test_shipping_settings_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        ShippingSetting::create(['express_post_fee' => 15]);

        app(CurrentStore::class)->set($this->storeB);
        ShippingSetting::create(['express_post_fee' => 99]);

        app(CurrentStore::class)->set($this->storeA);
        $this->assertEquals(15, ShippingSetting::first()?->express_post_fee);

        app(CurrentStore::class)->set($this->storeB);
        $this->assertEquals(99, ShippingSetting::first()?->express_post_fee);
    }

    public function test_only_one_shipping_setting_row_allowed_per_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        ShippingSetting::create(['express_post_fee' => 15]);

        $this->expectException(QueryException::class);
        ShippingSetting::create(['express_post_fee' => 20]);
    }

    public function test_tax_settings_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        TaxSetting::create(['tax_enabled' => true, 'tax_rate' => 12, 'tax_name' => 'VAT']);

        app(CurrentStore::class)->set($this->storeB);
        TaxSetting::create(['tax_enabled' => false, 'tax_rate' => 0, 'tax_name' => 'None']);

        app(CurrentStore::class)->set($this->storeA);
        $this->assertEquals('VAT', TaxSetting::first()?->tax_name);

        app(CurrentStore::class)->set($this->storeB);
        $this->assertEquals('None', TaxSetting::first()?->tax_name);
    }

    public function test_payment_settings_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        PaymentSetting::create(['cash_enabled' => true, 'stripe_enabled' => true]);

        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create(['cash_enabled' => false, 'stripe_enabled' => false]);

        app(CurrentStore::class)->set($this->storeA);
        $this->assertTrue((bool) PaymentSetting::first()?->stripe_enabled);

        app(CurrentStore::class)->set($this->storeB);
        $this->assertFalse((bool) PaymentSetting::first()?->stripe_enabled);
    }

    public function test_tax_rules_are_isolated_by_store(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        TaxRule::create(['region_type' => 'all', 'tax_rate' => 10, 'tax_name' => 'A-Tax', 'enabled' => true, 'priority' => 0]);

        app(CurrentStore::class)->set($this->storeB);
        TaxRule::create(['region_type' => 'all', 'tax_rate' => 20, 'tax_name' => 'B-Tax', 'enabled' => true, 'priority' => 0]);

        app(CurrentStore::class)->set($this->storeA);
        $this->assertSame(1, TaxRule::count());
        $this->assertSame('A-Tax', TaxRule::forRegion(null, null)?->tax_name);

        app(CurrentStore::class)->set($this->storeB);
        $this->assertSame('B-Tax', TaxRule::forRegion(null, null)?->tax_name);
    }

    public function test_two_stores_can_each_have_the_same_shipping_zone_type(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        ShippingZone::create(['zone_type' => 'own_city', 'enabled' => true, 'base_rate' => 50]);

        app(CurrentStore::class)->set($this->storeB);
        ShippingZone::create(['zone_type' => 'own_city', 'enabled' => true, 'base_rate' => 80]);

        $this->assertSame(2, ShippingZone::withoutGlobalScope(\App\Models\Scopes\StoreScope::class)
            ->where('zone_type', 'own_city')
            ->count());
    }

    public function test_duplicate_shipping_zone_type_within_one_store_rejected(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        ShippingZone::create(['zone_type' => 'own_city', 'enabled' => true, 'base_rate' => 50]);

        $this->expectException(QueryException::class);
        ShippingZone::create(['zone_type' => 'own_city', 'enabled' => true, 'base_rate' => 60]);
    }

    public function test_admin_payment_settings_endpoint_is_scoped_to_their_store(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $adminA = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeA->id]);
        $adminA->roles()->attach($adminRole);

        $adminB = User::factory()->create(['is_admin' => true, 'store_id' => $this->storeB->id]);
        $adminB->roles()->attach($adminRole);

        app(CurrentStore::class)->clear();

        // Admin A enables stripe.
        $this->actingAs($adminA)
            ->patchJson('/api/v1/payment-settings', ['stripe_enabled' => true])
            ->assertOk();

        app(CurrentStore::class)->clear();

        // Admin B's settings are untouched (stripe stays disabled by default).
        $this->actingAs($adminB)
            ->getJson('/api/v1/payment-settings')
            ->assertOk()
            ->assertJsonPath('data.stripe_enabled', false);
    }
}
