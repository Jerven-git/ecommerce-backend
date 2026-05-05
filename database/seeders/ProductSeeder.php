<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $electronics = Category::create(['name' => 'Electronics', 'sort_order' => 1]);
        $clothing = Category::create(['name' => 'Clothing', 'sort_order' => 2]);
        $homeKitchen = Category::create(['name' => 'Home & Kitchen', 'sort_order' => 3]);

        // Subcategories — exercise the recursive picker UI.
        $audio = Category::create(['name' => 'Audio', 'parent_id' => $electronics->id, 'sort_order' => 1]);
        $wearables = Category::create(['name' => 'Wearables', 'parent_id' => $electronics->id, 'sort_order' => 2]);
        $tops = Category::create(['name' => 'Tops', 'parent_id' => $clothing->id, 'sort_order' => 1]);
        $cookware = Category::create(['name' => 'Cookware', 'parent_id' => $homeKitchen->id, 'sort_order' => 1]);

        $rows = [
            [
                'name' => 'Wireless Bluetooth Earbuds',
                'description' => 'Compact wireless earbuds with noise cancellation and 24-hour battery life.',
                'price' => 49.99,
                'stock' => 50,
                'weight' => 0.15,
                'length_cm' => 8.00, 'width_cm' => 6.00, 'height_cm' => 3.00,
                'shipping_calc_type' => 'weight',
                'category_id' => $audio->id,
                'category_ids' => [$electronics->id, $audio->id],
                'image_url' => 'https://picsum.photos/seed/earbuds/800/800',
                'seo_title' => 'Wireless Bluetooth Earbuds — 24h Battery, Noise Cancelling',
                'seo_description' => 'Compact true-wireless earbuds with active noise cancellation, 24-hour battery, and crystal-clear calls.',
                'og_image_url' => 'https://picsum.photos/seed/earbuds-og/1200/630',
            ],
            [
                'name' => 'Cotton Graphic T-Shirt',
                'description' => 'Soft 100% cotton t-shirt with printed graphic design.',
                'price' => 19.99,
                'stock' => 80,
                'weight' => 0.25,
                'length_cm' => 30.00, 'width_cm' => 25.00, 'height_cm' => 3.00,
                'shipping_calc_type' => 'weight',
                'category_id' => $tops->id,
                'category_ids' => [$clothing->id, $tops->id],
                'image_url' => 'https://picsum.photos/seed/tshirt/800/800',
            ],
            [
                'name' => 'Standing Desk Lamp',
                'description' => 'Adjustable LED desk lamp with multiple brightness levels and USB charging port.',
                'price' => 34.50,
                'stock' => 50,
                'weight' => 1.20,
                'length_cm' => 45.00, 'width_cm' => 15.00, 'height_cm' => 15.00,
                'shipping_calc_type' => 'dimensions',
                'category_id' => $homeKitchen->id,
                'category_ids' => [$homeKitchen->id],
                'image_url' => 'https://picsum.photos/seed/desklamp/800/800',
            ],
            [
                'name' => '27-inch Monitor',
                'description' => '27-inch IPS monitor with 4K resolution and HDR support.',
                'price' => 299.99,
                'stock' => 30,
                'weight' => 5.50,
                'length_cm' => 65.00, 'width_cm' => 45.00, 'height_cm' => 20.00,
                'shipping_calc_type' => 'dimensions',
                'category_id' => $electronics->id,
                'category_ids' => [$electronics->id],
                'image_url' => 'https://picsum.photos/seed/monitor/800/800',
                'seo_title' => '27-inch 4K HDR IPS Monitor — Pro-grade Color Accuracy',
                'seo_description' => 'Sharp 4K IPS panel with HDR and 99% sRGB coverage. Perfect for design, video, and everyday productivity.',
            ],
            [
                'name' => 'Stainless Steel Cookware Set',
                'description' => '10-piece stainless steel cookware set with glass lids.',
                'price' => 129.99,
                'stock' => 40,
                'weight' => 8.00,
                'length_cm' => 55.00, 'width_cm' => 35.00, 'height_cm' => 30.00,
                'shipping_calc_type' => 'dimensions',
                'category_id' => $cookware->id,
                'category_ids' => [$homeKitchen->id, $cookware->id],
                'image_url' => 'https://picsum.photos/seed/cookware/800/800',
            ],
            // Multi-category demo product — exercises the categories() pivot
            // by belonging to BOTH Electronics > Audio AND Electronics > Wearables.
            [
                'name' => 'Smart Fitness Watch',
                'description' => 'Heart-rate, sleep tracking, and bluetooth audio in a sleek aluminium body.',
                'price' => 199.00,
                'stock' => 25,
                'weight' => 0.08,
                'length_cm' => 5.00, 'width_cm' => 4.00, 'height_cm' => 1.50,
                'shipping_calc_type' => 'weight',
                'category_id' => $wearables->id,
                'category_ids' => [$electronics->id, $audio->id, $wearables->id],
                'image_url' => 'https://picsum.photos/seed/smartwatch/800/800',
                'seo_title' => 'Smart Fitness Watch — Heart Rate, Sleep, Bluetooth Audio',
                'seo_description' => 'A premium fitness companion that tracks heart rate, sleep, and over 50 workout types — with onboard music storage.',
                'og_image_url' => 'https://picsum.photos/seed/smartwatch-og/1200/630',
            ],
        ];

        foreach ($rows as $row) {
            $categoryIds = $row['category_ids'];
            unset($row['category_ids']);

            $product = Product::create([
                ...$row,
                'slug' => Product::generateUniqueSlug($row['name']),
            ]);

            // Sync the belongsToMany pivot — this is what the admin product
            // list reads via `Product::with('categories')`. The legacy
            // category_id scalar above is preserved for older display paths.
            $product->categories()->sync($categoryIds);
        }
    }
}
