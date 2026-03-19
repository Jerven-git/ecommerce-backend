<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);

        $this->product = Product::factory()->create([
            'name' => 'Test Product',
            'price' => 25.00,
            'stock' => 10,
        ]);
    }

    // ─────────────────────────────────────────
    // Order Creation
    // ─────────────────────────────────────────

    public function test_can_create_order_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.order.status', 'pending')
            ->assertJsonPath('data.order.customer_name', 'John Doe');
    }

    public function test_order_creation_requires_items(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_order_creation_requires_customer_fields(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'delivery_method' => 'pickup',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'customer_email']);
    }

    public function test_order_creation_validates_email(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'not-an-email',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('customer_email');
    }

    public function test_order_creation_fails_with_nonexistent_product(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'john@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => 99999, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_order_creation_fails_with_insufficient_stock(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'john@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 999],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_delivery_order_requires_address_fields(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'john@example.com',
            'delivery_method' => 'delivery',
            'shipping_address' => '123 Main St',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // Order Status Transitions
    // ─────────────────────────────────────────

    public function test_admin_can_update_order_status(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/orders/{$order->id}", [
                'status' => 'processing',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/orders/{$order->id}", [
                'status' => 'delivered',
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_cancel_order_with_paid_payment(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        Payment::create([
            'order_id' => $order->id,
            'provider' => 'cash',
            'status' => 'paid',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/orders/{$order->id}", [
                'status' => 'cancelled',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot cancel an order with a completed payment. Please undo the payment first.']);
    }

    // ─────────────────────────────────────────
    // Order Listing (Auth Required)
    // ─────────────────────────────────────────

    public function test_unauthenticated_cannot_list_orders(): void
    {
        $this->getJson('/api/v1/orders')->assertStatus(401);
    }

    public function test_unauthenticated_cannot_view_order(): void
    {
        $order = Order::factory()->create();
        $this->getJson("/api/v1/orders/{$order->id}")->assertStatus(401);
    }

    public function test_admin_can_list_orders(): void
    {
        Order::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_filter_orders_by_status(): void
    {
        Order::factory()->create(['status' => 'pending']);
        Order::factory()->create(['status' => 'processing']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/orders?status=pending');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ─────────────────────────────────────────
    // Order Deletion
    // ─────────────────────────────────────────

    public function test_can_delete_pending_order(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/orders/{$order->id}")
            ->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_cannot_delete_processing_order(): void
    {
        $order = Order::factory()->create(['status' => 'processing']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/orders/{$order->id}")
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // XSS Sanitization
    // ─────────────────────────────────────────

    public function test_xss_is_stripped_from_order_fields(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => '<script>alert(1)</script>John',
            'customer_email' => 'john@example.com',
            'customer_phone' => '<img onerror=alert(1)>555-1234',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $order = Order::latest()->first();
        $this->assertStringNotContainsString('<script>', $order->customer_name);
        $this->assertStringNotContainsString('<img', $order->customer_phone);
    }

    public function test_email_is_lowercased_on_order(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'JOHN@EXAMPLE.COM',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $order = Order::latest()->first();
        $this->assertEquals('john@example.com', $order->customer_email);
    }

    // ─────────────────────────────────────────
    // Discount on Order
    // ─────────────────────────────────────────

    public function test_discount_code_is_uppercased(): void
    {
        Discount::factory()->create(['code' => 'SAVE10', 'type' => 'fixed', 'value' => 10]);

        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'john@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'discount_code' => 'save10',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $order = Order::latest()->first();
        $this->assertEquals('SAVE10', $order->discount_code);
    }
}
