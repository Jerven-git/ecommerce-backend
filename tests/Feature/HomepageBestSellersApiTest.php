<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionLifetimeMiddleware;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageBestSellersApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware(SessionLifetimeMiddleware::class);
    }

    private function makeProduct(string $name): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Product::generateUniqueSlug($name),
            'description' => 'A product',
            'price' => 9.99,
            'stock' => 100,
            'image_url' => 'https://example.test/'.$name.'.jpg',
            'is_active' => true,
        ]);
    }

    /**
     * Build a delivered order with one line item for $product. We build orders
     * one-per-row so the controller's "min distinct orders" gate fires
     * realistically (the threshold is on COUNT(DISTINCT order_id), not units).
     */
    private function recordSale(Product $product, int $quantity = 1): Order
    {
        $order = Order::create([
            'customer_email' => 'guest+'.uniqid().'@example.test',
            'customer_name' => 'Guest',
            'shipping_address' => '1 Test Lane',
            'total_amount' => $product->price * $quantity,
            'subtotal' => $product->price * $quantity,
            'status' => 'delivered',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => $product->price,
            'quantity' => $quantity,
            'subtotal' => $product->price * $quantity,
        ]);

        return $order;
    }

    public function test_show_returns_default_best_sellers_shape(): void
    {
        $this->getJson('/api/v1/site-config')
            ->assertOk()
            ->assertJsonPath('data.homepage_best_sellers.enabled', false)
            ->assertJsonPath('data.homepage_best_sellers.heading', '')
            ->assertJsonPath('data.homepage_best_sellers.products', []);
    }

    public function test_show_uses_fallback_when_no_orders_exist(): void
    {
        $a = $this->makeProduct('Promo A');
        $b = $this->makeProduct('Promo B');

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_best_sellers' => [
                    'enabled' => true,
                    'heading' => 'Best Sellers',
                    'fallback_product_ids' => [$a->id, $b->id],
                ],
            ])->assertOk();

        $response = $this->getJson('/api/v1/site-config')->assertOk();

        $response->assertJsonPath('data.homepage_best_sellers.source', 'fallback');
        $this->assertSame(
            [$a->id, $b->id],
            collect($response->json('data.homepage_best_sellers.products'))->pluck('id')->all(),
        );
    }

    public function test_show_uses_auto_query_when_enough_signal(): void
    {
        $a = $this->makeProduct('Top Seller');
        $b = $this->makeProduct('Runner Up');
        $c = $this->makeProduct('Promoted Pick');

        // 3 distinct orders for product A (winner), 1 order with 5 units of B
        // (loses on order count even though it has more units? no — units_sold
        // is what we sort by, but the *signal* gate is distinct orders).
        $this->recordSale($a, 1);
        $this->recordSale($a, 1);
        $this->recordSale($a, 1);
        $this->recordSale($b, 5);

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_best_sellers' => [
                    'enabled' => true,
                    'heading' => 'Best Sellers',
                    // Curated list should be ignored once auto signal kicks in.
                    'fallback_product_ids' => [$c->id],
                ],
            ])->assertOk();

        $response = $this->getJson('/api/v1/site-config')->assertOk();

        $response->assertJsonPath('data.homepage_best_sellers.source', 'auto');
        $ids = collect($response->json('data.homepage_best_sellers.products'))->pluck('id')->all();
        $this->assertNotContains($c->id, $ids, 'Fallback should be ignored when auto signal exists.');
        // B has more units (5 vs 3) so it should rank above A despite fewer orders.
        $this->assertSame([$b->id, $a->id], $ids);
    }

    public function test_cancelled_orders_dont_count_toward_best_sellers(): void
    {
        $a = $this->makeProduct('Cancelled Heavy');
        $fallback = $this->makeProduct('Curated Pick');

        // Three orders, all cancelled — should NOT cross the auto threshold.
        for ($i = 0; $i < 3; $i++) {
            $order = Order::create([
                'customer_email' => 'cancelled+'.$i.'@example.test',
                'customer_name' => 'Guest',
                'shipping_address' => '1 Test Lane',
                'total_amount' => 9.99,
                'subtotal' => 9.99,
                'status' => 'cancelled',
            ]);
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $a->id,
                'product_name' => $a->name,
                'product_price' => $a->price,
                'quantity' => 1,
                'subtotal' => 9.99,
            ]);
        }

        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'homepage_best_sellers' => [
                'enabled' => true,
                'heading' => 'Best Sellers',
                'fallback_product_ids' => [$fallback->id],
            ],
        ])->assertOk();

        $response = $this->getJson('/api/v1/site-config')->assertOk();

        $response->assertJsonPath('data.homepage_best_sellers.source', 'fallback');
        $this->assertSame(
            [$fallback->id],
            collect($response->json('data.homepage_best_sellers.products'))->pluck('id')->all(),
        );
    }

    public function test_inactive_products_are_excluded(): void
    {
        $live = $this->makeProduct('Live');
        $hidden = $this->makeProduct('Hidden');
        $hidden->update(['is_active' => false]);

        $this->asAdmin()->patchJson('/api/v1/site-config', [
            'homepage_best_sellers' => [
                'enabled' => true,
                'heading' => 'Best Sellers',
                'fallback_product_ids' => [$hidden->id, $live->id],
            ],
        ])->assertOk();

        $response = $this->getJson('/api/v1/site-config')->assertOk();

        $this->assertSame(
            [$live->id],
            collect($response->json('data.homepage_best_sellers.products'))->pluck('id')->all(),
        );
    }

    public function test_update_rejects_enabled_without_heading(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_best_sellers' => [
                    'enabled' => true,
                    'heading' => '',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_best_sellers.heading');
    }

    public function test_update_rejects_unknown_fallback_product_id(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_best_sellers' => [
                    'enabled' => false,
                    'fallback_product_ids' => [99999],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_best_sellers.fallback_product_ids.0');
    }

    public function test_update_rejects_too_many_fallback_ids(): void
    {
        $products = collect(range(1, 9))->map(fn ($i) => $this->makeProduct('P'.$i));

        $this->asAdmin()
            ->patchJson('/api/v1/site-config', [
                'homepage_best_sellers' => [
                    'enabled' => false,
                    'fallback_product_ids' => $products->pluck('id')->all(),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('homepage_best_sellers.fallback_product_ids');
    }
}
