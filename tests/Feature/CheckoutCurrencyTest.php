<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::factory()->base()->create();
        Currency::factory()->create([
            'code' => 'AUD',
            'name' => 'Australian Dollar',
            'symbol' => 'A$',
            'decimal_places' => 2,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Artwork',
            'price' => 100.00,
            'stock' => 5,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'country' => 'US',
            'state' => 'CA',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ], $overrides);
    }

    public function test_order_uses_default_usd_when_site_config_unset(): void
    {
        $this->postJson('/api/v1/orders', $this->payload())
            ->assertStatus(201);

        $order = Order::first();
        $this->assertSame('USD', $order->currency);
        $this->assertSame('100.00', $order->total_amount);
    }

    public function test_order_inherits_shop_currency_from_site_config(): void
    {
        SiteConfig::query()->create(['currency_code' => 'AUD']);

        $this->postJson('/api/v1/orders', $this->payload())
            ->assertStatus(201);

        $order = Order::first();
        // Shop is configured as AUD; product price 100 is treated as 100 AUD
        // (no conversion happens — the merchant entered prices in their currency).
        $this->assertSame('AUD', $order->currency);
        $this->assertSame('100.00', $order->total_amount);
    }

    public function test_customer_currency_in_request_is_ignored(): void
    {
        SiteConfig::query()->create(['currency_code' => 'AUD']);

        $this->postJson('/api/v1/orders', $this->payload(['currency' => 'PHP']))
            ->assertStatus(201);

        // Even though the customer's payload tried to specify PHP, the order
        // is locked to the shop's configured currency (AUD).
        $this->assertSame('AUD', Order::first()->currency);
    }

    public function test_order_items_store_catalog_price(): void
    {
        SiteConfig::query()->create(['currency_code' => 'AUD']);

        $this->postJson('/api/v1/orders', $this->payload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ]))->assertStatus(201);

        $item = Order::first()->items()->first();
        $this->assertSame('100.00', $item->product_price);
        $this->assertSame('200.00', $item->subtotal);
    }
}
