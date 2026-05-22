<?php

namespace Tests\Feature\Tenancy;

use App\Models\PaymentSetting;
use App\Models\Store;
use App\Payments\PaymentCredentials;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected Store $storeA;

    protected Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payment.stripe.secret_key', 'sk_config_fallback');
        config()->set('payment.stripe.publishable_key', 'pk_config_fallback');

        $this->storeA = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );
        $this->storeB = Store::factory()->create(['name' => 'Watch World']);
    }

    public function test_store_value_wins_over_config(): void
    {
        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create(['stripe_secret_key' => 'sk_store_b']);

        $this->assertSame('sk_store_b', app(PaymentCredentials::class)->get('stripe', 'secret_key'));
    }

    public function test_falls_back_to_config_when_store_value_missing(): void
    {
        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create([]); // no stripe key set

        $this->assertSame('sk_config_fallback', app(PaymentCredentials::class)->get('stripe', 'secret_key'));
    }

    public function test_falls_back_to_config_when_no_settings_row_exists(): void
    {
        app(CurrentStore::class)->set($this->storeB);

        $this->assertSame('sk_config_fallback', app(PaymentCredentials::class)->get('stripe', 'secret_key'));
    }

    public function test_credentials_are_isolated_across_stores(): void
    {
        app(CurrentStore::class)->set($this->storeA);
        PaymentSetting::create(['stripe_secret_key' => 'sk_store_a']);

        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create(['stripe_secret_key' => 'sk_store_b']);

        // Resolve per store — must not bleed across (a fresh resolver each time
        // since the singleton memoizes per store id within one instance).
        app(CurrentStore::class)->set($this->storeA);
        $this->assertSame('sk_store_a', app()->make(PaymentCredentials::class)->get('stripe', 'secret_key'));

        app(CurrentStore::class)->set($this->storeB);
        $this->assertSame('sk_store_b', app()->make(PaymentCredentials::class)->get('stripe', 'secret_key'));
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        app(CurrentStore::class)->set($this->storeB);
        PaymentSetting::create(['stripe_secret_key' => 'sk_super_secret']);

        $raw = DB::table('payment_settings')->where('store_id', $this->storeB->id)->value('stripe_secret_key');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('sk_super_secret', $raw);
    }

    public function test_secrets_never_appear_in_serialized_model(): void
    {
        app(CurrentStore::class)->set($this->storeB);
        $settings = PaymentSetting::create([
            'stripe_secret_key' => 'sk_hidden',
            'square_access_token' => 'sq_hidden',
        ]);

        $array = $settings->toArray();

        $this->assertArrayNotHasKey('stripe_secret_key', $array);
        $this->assertArrayNotHasKey('square_access_token', $array);
        $this->assertTrue($array['stripe_secret_key_configured']);
        $this->assertFalse($array['paypal_secret_configured']);
    }
}
