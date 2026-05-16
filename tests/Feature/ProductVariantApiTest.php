<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProductVariantApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $this->admin->roles()->attach($role);

        $this->product = Product::factory()->create([
            'price' => 100.00,
            'stock' => 50,
        ]);
    }

    // ─────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────

    private function colorSizePayload(): array
    {
        return [
            'options' => [
                [
                    'id' => null,
                    'name' => 'Color',
                    'position' => 0,
                    'values' => [
                        ['id' => null, 'label' => 'Red', 'image_url' => null, 'position' => 0],
                        ['id' => null, 'label' => 'Blue', 'image_url' => null, 'position' => 1],
                    ],
                ],
                [
                    'id' => null,
                    'name' => 'Size',
                    'position' => 1,
                    'values' => [
                        ['id' => null, 'label' => 'S', 'image_url' => null, 'position' => 0],
                        ['id' => null, 'label' => 'M', 'image_url' => null, 'position' => 1],
                    ],
                ],
            ],
            'variants' => [],
        ];
    }

    /**
     * Creates a Color option with one value, plus a matching active variant.
     */
    private function createColorVariant(string $color, string $sku, int $stock): ProductVariant
    {
        $option = $this->product->options()->create(['name' => 'Color', 'position' => 0]);
        $value = $option->values()->create(['label' => $color, 'position' => 0]);
        $variant = $this->product->variants()->create([
            'sku' => $sku,
            'price' => null,
            'stock' => $stock,
            'is_active' => true,
        ]);
        $variant->optionValues()->sync([$value->id => ['product_option_id' => $option->id]]);

        return $variant;
    }

    // ─────────────────────────────────────────
    // Admin sync: basic creation
    // ─────────────────────────────────────────

    public function test_admin_can_sync_variants_and_options(): void
    {
        $payload = [
            'options' => [
                [
                    'id' => null,
                    'name' => 'Color',
                    'position' => 0,
                    'values' => [
                        ['id' => null, 'label' => 'Red', 'image_url' => null, 'position' => 0],
                        ['id' => null, 'label' => 'Blue', 'image_url' => null, 'position' => 1],
                    ],
                ],
            ],
            'variants' => [],
        ];

        $this->actingAs($this->admin)
            ->postJson("/api/v1/products/{$this->product->id}/variants/sync", $payload)
            ->assertOk()
            ->assertJsonPath('data.options.0.name', 'Color')
            ->assertJsonCount(2, 'data.options.0.values');

        $this->assertDatabaseHas('product_options', ['product_id' => $this->product->id, 'name' => 'Color']);
        $this->assertDatabaseHas('product_option_values', ['label' => 'Red']);
        $this->assertDatabaseHas('product_option_values', ['label' => 'Blue']);
    }

    public function test_unauthenticated_cannot_sync_variants(): void
    {
        $this->postJson("/api/v1/products/{$this->product->id}/variants/sync", ['options' => [], 'variants' => []])
            ->assertUnauthorized();
    }

    // ─────────────────────────────────────────
    // Pivot rows
    // ─────────────────────────────────────────

    public function test_sync_creates_correct_pivot_rows(): void
    {
        $firstSync = $this->actingAs($this->admin)
            ->postJson("/api/v1/products/{$this->product->id}/variants/sync", $this->colorSizePayload())
            ->assertOk();

        $colorOption = $firstSync->json('data.options.0');
        $sizeOption = $firstSync->json('data.options.1');
        $redId = $colorOption['values'][0]['id'];
        $sId = $sizeOption['values'][0]['id'];

        $payload = [
            'options' => [
                [
                    'id' => $colorOption['id'],
                    'name' => 'Color',
                    'position' => 0,
                    'values' => [
                        ['id' => $redId, 'label' => 'Red', 'image_url' => null, 'position' => 0],
                        ['id' => $colorOption['values'][1]['id'], 'label' => 'Blue', 'image_url' => null, 'position' => 1],
                    ],
                ],
                [
                    'id' => $sizeOption['id'],
                    'name' => 'Size',
                    'position' => 1,
                    'values' => [
                        ['id' => $sId, 'label' => 'S', 'image_url' => null, 'position' => 0],
                        ['id' => $sizeOption['values'][1]['id'], 'label' => 'M', 'image_url' => null, 'position' => 1],
                    ],
                ],
            ],
            'variants' => [
                [
                    'id' => null,
                    'sku' => 'RED-S',
                    'price' => null,
                    'stock' => 10,
                    'image_url' => null,
                    'is_active' => true,
                    'option_value_ids' => [$redId, $sId],
                ],
            ],
        ];

        $this->actingAs($this->admin)
            ->postJson("/api/v1/products/{$this->product->id}/variants/sync", $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.variants');

        $variant = ProductVariant::where('sku', 'RED-S')->first();
        $this->assertNotNull($variant);
        $this->assertCount(2, $variant->optionValues);
        $this->assertDatabaseHas('product_variant_option_values', [
            'product_variant_id' => $variant->id,
            'product_option_value_id' => $redId,
        ]);
    }

    // ─────────────────────────────────────────
    // Option removal
    // ─────────────────────────────────────────

    public function test_sync_removes_options_not_in_payload(): void
    {
        $firstSync = $this->actingAs($this->admin)
            ->postJson("/api/v1/products/{$this->product->id}/variants/sync", $this->colorSizePayload())
            ->assertOk();

        $colorOption = $firstSync->json('data.options.0');

        $payload = [
            'options' => [
                [
                    'id' => $colorOption['id'],
                    'name' => 'Color',
                    'position' => 0,
                    'values' => [
                        ['id' => $colorOption['values'][0]['id'], 'label' => 'Red', 'image_url' => null, 'position' => 0],
                    ],
                ],
            ],
            'variants' => [],
        ];

        $this->actingAs($this->admin)
            ->postJson("/api/v1/products/{$this->product->id}/variants/sync", $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.options');

        $this->assertDatabaseMissing('product_options', ['name' => 'Size', 'product_id' => $this->product->id]);
    }

    // ─────────────────────────────────────────
    // Order: variant snapshot stored
    // ─────────────────────────────────────────

    public function test_order_with_variant_id_creates_item_with_snapshot(): void
    {
        $variant = $this->createColorVariant('Red', sku: 'RED-VAR', stock: 20);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Test User',
            'customer_email' => 'test@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'variant_id' => $variant->id],
            ],
        ])->assertStatus(201);

        $item = OrderItem::where('product_id', $this->product->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals($variant->id, $item->variant_id);
        $this->assertEquals('RED-VAR', $item->variant_sku);
        $this->assertNotNull($item->selected_options);
    }

    // ─────────────────────────────────────────
    // Stock deduction: variant vs product
    // ─────────────────────────────────────────

    public function test_order_deducts_variant_stock_not_product_stock(): void
    {
        $variant = $this->createColorVariant('Red', sku: 'RED-V', stock: 15);
        $productStockBefore = $this->product->fresh()->stock;

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Customer',
            'customer_email' => 'cust@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'variant_id' => $variant->id],
            ],
        ])->assertStatus(201);

        $order = Order::latest()->first();
        Payment::create([
            'order_id' => $order->id,
            'provider' => 'cash',
            'status' => 'pending',
            'amount' => 300,
            'currency' => 'USD',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertOk();

        $this->assertEquals(12, $variant->fresh()->stock);
        $this->assertEquals($productStockBefore, $this->product->fresh()->stock);
    }

    public function test_order_fails_when_variant_has_insufficient_stock(): void
    {
        $variant = $this->createColorVariant('Blue', sku: 'BLUE-V', stock: 2);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Customer',
            'customer_email' => 'cust@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'variant_id' => $variant->id],
            ],
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // Backward compatibility: plain products
    // ─────────────────────────────────────────

    public function test_order_without_variant_id_still_works(): void
    {
        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Plain User',
            'customer_email' => 'plain@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ])->assertStatus(201);

        $item = OrderItem::where('product_id', $this->product->id)->first();
        $this->assertNull($item->variant_id);
        $this->assertNull($item->variant_sku);
    }

    // ─────────────────────────────────────────
    // Stock restoration on cancel
    // ─────────────────────────────────────────

    public function test_restore_stock_restores_variant_stock_on_cancel(): void
    {
        $variant = $this->createColorVariant('Green', sku: 'GRN-V', stock: 10);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Canceller',
            'customer_email' => 'cancel@example.com',
            'delivery_method' => 'pickup',
            'shipping_address' => '',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 4, 'variant_id' => $variant->id],
            ],
        ])->assertStatus(201);

        $order = Order::latest()->first();
        Payment::create([
            'order_id' => $order->id,
            'provider' => 'cash',
            'status' => 'pending',
            'amount' => 400,
            'currency' => 'USD',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertOk();

        $this->assertEquals(6, $variant->fresh()->stock);

        // Undo payment so the order can be cancelled, and restoreStock runs
        $this->actingAs($this->admin)
            ->postJson("/api/v1/orders/{$order->id}/undo-payment")
            ->assertOk();

        $this->assertEquals(10, $variant->fresh()->stock);
    }

    // ─────────────────────────────────────────
    // Public product detail endpoint
    // ─────────────────────────────────────────

    public function test_product_detail_endpoint_includes_variants(): void
    {
        $this->createColorVariant('Red', sku: 'RED', stock: 5);

        $this->getJson("/api/v1/products/{$this->product->slug}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'options' => [['id', 'name', 'values']],
                    'variants' => [['id', 'stock', 'option_values']],
                ],
            ]);
    }

    // ─────────────────────────────────────────
    // Index endpoint
    // ─────────────────────────────────────────

    public function test_admin_can_get_variant_index(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/v1/products/{$this->product->id}/variants")
            ->assertOk()
            ->assertJsonStructure(['data' => ['options', 'variants']]);
    }
}
