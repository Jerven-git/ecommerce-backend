<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\SiteConfig;
use App\Models\TaxSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanitizationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────
    // Model Mutator Tests
    // ─────────────────────────────────────────

    public function test_order_email_lowercased(): void
    {
        $order = Order::factory()->create(['customer_email' => 'JOHN@EXAMPLE.COM']);
        $this->assertEquals('john@example.com', $order->customer_email);
    }

    public function test_order_name_strips_tags(): void
    {
        $order = Order::factory()->create(['customer_name' => '<b>John</b><script>x</script>']);
        $this->assertStringNotContainsString('<script>', $order->customer_name);
        $this->assertStringNotContainsString('<b>', $order->customer_name);
        $this->assertStringContainsString('John', $order->customer_name);
    }

    public function test_order_address_strips_tags(): void
    {
        $order = Order::factory()->create(['shipping_address' => '<img src=x onerror=alert(1)>123 Main St']);
        $this->assertStringNotContainsString('<img', $order->shipping_address);
        $this->assertStringContainsString('123 Main St', $order->shipping_address);
    }

    public function test_order_discount_code_uppercased(): void
    {
        $order = Order::factory()->create(['discount_code' => '  summer10  ']);
        $this->assertEquals('SUMMER10', $order->discount_code);
    }

    public function test_order_fields_trimmed(): void
    {
        $order = Order::factory()->create([
            'country' => '  US  ',
            'state' => '  CA  ',
            'city' => '  LA  ',
            'customer_phone' => '  555-1234  ',
        ]);

        $this->assertEquals('US', $order->country);
        $this->assertEquals('CA', $order->state);
        $this->assertEquals('LA', $order->city);
        $this->assertEquals('555-1234', $order->customer_phone);
    }

    public function test_user_email_lowercased(): void
    {
        $user = User::factory()->create(['email' => '  ADMIN@TEST.COM  ']);
        $this->assertEquals('admin@test.com', $user->email);
    }

    public function test_user_name_trimmed(): void
    {
        $user = User::factory()->create(['name' => '  Jane  ']);
        $this->assertEquals('Jane', $user->name);
    }

    public function test_product_name_sanitized(): void
    {
        $product = Product::factory()->create(['name' => '  <script>x</script>Widget  ']);
        $this->assertStringNotContainsString('<script>', $product->name);
        $this->assertStringContainsString('Widget', $product->name);
    }

    public function test_product_description_trimmed(): void
    {
        $product = Product::factory()->create(['description' => '  A great product  ']);
        $this->assertEquals('A great product', $product->description);
    }

    public function test_category_name_sanitized(): void
    {
        $cat = Category::create(['name' => '  <b>Electronics</b>  ']);
        $this->assertStringNotContainsString('<b>', $cat->name);
        $this->assertStringContainsString('Electronics', $cat->name);
    }

    public function test_discount_code_uppercased_and_trimmed(): void
    {
        $discount = Discount::factory()->create(['code' => '  save20  ']);
        $this->assertEquals('SAVE20', $discount->code);
    }

    public function test_discount_description_strips_tags(): void
    {
        $discount = Discount::factory()->create(['description' => '<script>x</script>Sale']);
        $this->assertStringNotContainsString('<script>', $discount->description);
        $this->assertStringContainsString('Sale', $discount->description);
    }

    public function test_payment_provider_lowercased(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => '  STRIPE  ',
            'status' => 'pending',
            'amount' => 1000,
            'currency' => 'usd',
        ]);

        $this->assertEquals('stripe', $payment->provider);
        $this->assertEquals('USD', $payment->currency);
    }

    public function test_shipment_tracking_uppercased(): void
    {
        $order = Order::factory()->create();
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => '  ssu-test-123  ',
            'status' => 'label_created',
            'shipped_at' => now(),
        ]);

        $this->assertEquals('SSU-TEST-123', $shipment->tracking_number);
    }

    public function test_shipment_carrier_strips_tags(): void
    {
        $order = Order::factory()->create();
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => 'SSU-TEST-456',
            'carrier' => '<b>FedEx</b>',
            'status' => 'label_created',
            'shipped_at' => now(),
        ]);

        $this->assertEquals('FedEx', $shipment->carrier);
    }

    public function test_site_config_sanitized(): void
    {
        $config = SiteConfig::create([
            'site_name' => '<script>x</script>My Store',
            'hero_title' => '<b>Welcome</b><script>x</script>',
            'hero_subtitle' => '<img onerror=x>Shop Now',
            'contact_email' => '  ADMIN@STORE.COM  ',
            'contact_phone' => '  +1234567890  ',
        ]);

        $this->assertStringNotContainsString('<script>', $config->site_name);
        $this->assertStringContainsString('My Store', $config->site_name);
        $this->assertStringNotContainsString('<script>', $config->hero_title);
        $this->assertStringContainsString('Welcome', $config->hero_title);
        $this->assertStringNotContainsString('<img', $config->hero_subtitle);
        $this->assertStringContainsString('Shop Now', $config->hero_subtitle);
        $this->assertEquals('admin@store.com', $config->contact_email);
        $this->assertEquals('+1234567890', $config->contact_phone);
    }

    public function test_tax_setting_name_strips_tags(): void
    {
        $tax = TaxSetting::create([
            'tax_name' => '<b>Tax</b><script>x</script>',
            'tax_enabled' => true,
            'tax_rate' => 10,
        ]);

        $this->assertStringNotContainsString('<script>', $tax->tax_name);
        $this->assertStringContainsString('Tax', $tax->tax_name);
    }

    public function test_order_item_product_name_strips_tags(): void
    {
        $order = Order::factory()->create();
        $product = Product::factory()->create();

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => '<script>x</script>Widget',
            'product_price' => 10,
            'quantity' => 1,
            'subtotal' => 10,
        ]);

        $this->assertStringNotContainsString('<script>', $item->product_name);
        $this->assertStringContainsString('Widget', $item->product_name);
    }

    public function test_role_name_lowercased(): void
    {
        $role = Role::create(['name' => '  ADMIN  ']);
        $this->assertEquals('admin', $role->name);
    }

    // ─────────────────────────────────────────
    // Middleware Sanitization (end-to-end)
    // ─────────────────────────────────────────

    public function test_middleware_strips_tags_from_request(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => '<script>alert("xss")</script>Clean Name',
            'customer_email' => 'test@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $order = Order::latest()->first();
        $this->assertStringNotContainsString('<script>', $order->customer_name);
        $this->assertStringContainsString('Clean Name', $order->customer_name);
    }

    public function test_middleware_preserves_allowed_html_in_description(): void
    {
        $admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $admin->roles()->attach($role);

        $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'price' => 10,
                'description' => '<p>A <strong>great</strong> product</p><script>x</script>',
            ])
            ->assertStatus(201);

        $product = Product::where('name', 'Test Product')->first();
        $this->assertStringContainsString('<p>', $product->description);
        $this->assertStringContainsString('<strong>', $product->description);
        $this->assertStringNotContainsString('<script>', $product->description);
    }

    public function test_null_values_are_not_broken_by_mutators(): void
    {
        $order = Order::factory()->create([
            'discount_code' => null,
            'customer_phone' => null,
            'country' => null,
        ]);

        $this->assertNull($order->discount_code);
        $this->assertNull($order->customer_phone);
        $this->assertNull($order->country);
    }
}
