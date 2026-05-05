<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Service;
use Database\Seeders\BlogSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\ServicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_seeder_syncs_categories_pivot(): void
    {
        $this->seed(ProductSeeder::class);

        // Every seeded product is wired through the categories() pivot —
        // not just the legacy category_id scalar — so admin filters that
        // read `Product::with('categories')` see populated arrays.
        $products = Product::with('categories')->get();
        $this->assertGreaterThan(0, $products->count());

        foreach ($products as $product) {
            $this->assertGreaterThan(
                0,
                $product->categories->count(),
                "Product '{$product->name}' has empty categories pivot."
            );
        }

        $smartwatch = Product::where('name', 'Smart Fitness Watch')->firstOrFail();
        $this->assertGreaterThanOrEqual(3, $smartwatch->categories->count());
    }

    public function test_product_seeder_creates_subcategories(): void
    {
        $this->seed(ProductSeeder::class);

        $electronics = Category::where('name', 'Electronics')->firstOrFail();
        $audio = Category::where('name', 'Audio')->firstOrFail();

        $this->assertSame($electronics->id, $audio->parent_id);
    }

    public function test_blog_seeder_writes_seo_fields_and_covers(): void
    {
        $this->seed(BlogSeeder::class);

        $post = Post::first();
        $this->assertNotNull($post);
        $this->assertNotNull($post->cover_image_url);
        $this->assertNotNull($post->cover_alt_text);
        $this->assertNotNull($post->seo_title);
        $this->assertNotNull($post->og_image_url);
    }

    public function test_services_seeder_writes_seo_fields_and_covers(): void
    {
        $this->seed(ServicesSeeder::class);

        $service = Service::first();
        $this->assertNotNull($service);
        $this->assertNotNull($service->cover_image_url);
        $this->assertNotNull($service->cover_alt_text);
        $this->assertNotNull($service->seo_title);
        $this->assertNotNull($service->og_image_url);
    }
}
