<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
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

    public function test_stats_returns_analytics_with_series_and_summary(): void
    {
        $product = Product::factory()->create(['name' => 'Widget', 'price' => 25.00]);

        $order = Order::factory()->create([
            'status' => 'pending',
            'total_amount' => 50.00,
            'created_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 25.00,
            'quantity' => 2,
            'subtotal' => 50.00,
        ]);
        Payment::create([
            'order_id' => $order->id,
            'provider' => 'stripe',
            'status' => 'paid',
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/stats?range=7d');

        $response->assertOk()
            ->assertJsonPath('data.analytics.range', '7d')
            ->assertJsonPath('data.analytics.granularity', 'day')
            ->assertJsonStructure([
                'data' => [
                    'stats' => ['total_products', 'total_orders', 'pending_orders', 'total_revenue', 'pending_revenue'],
                    'analytics' => [
                        'range', 'start_date', 'end_date', 'granularity',
                        'summary' => ['orders', 'revenue', 'aov', 'products'],
                        'series' => [['key', 'label', 'date', 'orders', 'revenue']],
                        'popular_products' => [['product_id', 'name', 'quantity_sold', 'revenue', 'order_count']],
                    ],
                    'recent_orders',
                ],
            ])
            ->assertJsonPath('data.analytics.summary.orders', 1)
            ->assertJsonPath('data.analytics.summary.revenue', 50);
    }

    public function test_stats_defaults_invalid_range_to_7d(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/stats?range=bogus');

        $response->assertOk()
            ->assertJsonPath('data.analytics.range', '7d')
            ->assertJsonPath('data.analytics.granularity', 'day')
            ->assertJsonCount(7, 'data.analytics.series');
    }

    public function test_stats_today_uses_hourly_buckets(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/stats?range=today');

        $response->assertOk()
            ->assertJsonPath('data.analytics.granularity', 'hour')
            ->assertJsonCount(24, 'data.analytics.series');
    }
}
