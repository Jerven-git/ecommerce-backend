<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductApiTest extends TestCase
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

    // ─────────────────────────────────────────
    // Public Endpoints
    // ─────────────────────────────────────────

    public function test_can_list_products(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_index_filters_by_ids(): void
    {
        $products = Product::factory()->count(4)->create();
        $wanted = $products->take(2);
        $idsParam = $wanted->pluck('id')->implode(',');

        $response = $this->getJson("/api/v1/products?ids={$idsParam}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $returnedIds = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame($wanted->pluck('id')->sort()->values()->all(), $returnedIds);
    }

    public function test_index_with_empty_ids_returns_no_products(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/v1/products?ids=')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_can_show_single_product(): void
    {
        $product = Product::factory()->create(['name' => 'Widget']);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Widget');
    }

    public function test_show_returns_404_for_nonexistent(): void
    {
        $this->getJson('/api/v1/products/99999')
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────
    // Admin CRUD
    // ─────────────────────────────────────────

    public function test_unauthenticated_cannot_create_product(): void
    {
        $this->postJson('/api/v1/products', [
            'name' => 'Test',
            'price' => 10,
        ])->assertStatus(401);
    }

    public function test_admin_can_create_product(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/products', [
                'name' => 'New Product',
                'price' => 29.99,
                'stock' => 50,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Product');
    }

    public function test_admin_can_update_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/products/{$product->id}", [
                'name' => 'Updated Name',
                'price' => 19.99,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_admin_can_delete_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_create_product_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/products', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'price']);
    }

    // ─────────────────────────────────────────
    // Sanitization
    // ─────────────────────────────────────────

    public function test_xss_stripped_from_product_name(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/products', [
                'name' => '<script>alert("xss")</script>Widget',
                'price' => 10,
            ])
            ->assertStatus(201);

        $product = Product::latest()->first();
        $this->assertStringNotContainsString('<script>', $product->name);
        $this->assertStringContainsString('Widget', $product->name);
    }

    public function test_product_name_is_trimmed(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/products', [
                'name' => '  Padded Name  ',
                'price' => 10,
            ])
            ->assertStatus(201);

        $this->assertEquals('Padded Name', Product::latest()->first()->name);
    }

    // ─────────────────────────────────────────
    // Secondary image + product specs
    // ─────────────────────────────────────────

    public function test_admin_can_set_material_and_dimensions(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/products', [
                'name' => 'Oak Dining Table',
                'price' => 500,
                'material' => 'Solid oak',
                'dimensions' => '180 × 90 × 75 cm',
            ])
            ->assertStatus(201);

        $product = Product::latest()->first();
        $this->assertSame('Solid oak', $product->material);
        $this->assertSame('180 × 90 × 75 cm', $product->dimensions);
    }

    public function test_index_exposes_spec_fields(): void
    {
        Product::factory()->create(['material' => 'Cotton', 'dimensions' => 'M']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.material', 'Cotton')
            ->assertJsonPath('data.0.dimensions', 'M');
    }

    public function test_admin_can_upload_hover_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/api/v1/products', [
                'name' => 'Sneakers',
                'price' => 120,
                'hover_image' => UploadedFile::fake()->image('back-view.jpg', 1200, 800),
            ])
            ->assertStatus(201);

        $product = Product::latest()->first();
        $this->assertNotNull($product->hover_image_url);
        $this->assertNotNull($product->media()->where('collection', 'hover')->first());
    }
}
