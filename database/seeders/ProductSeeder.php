<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $electronics = Category::create(['name' => 'Electronics', 'sort_order' => 1]);
        $clothing = Category::create(['name' => 'Clothing', 'sort_order' => 2]);
        $homeKitchen = Category::create(['name' => 'Home & Kitchen', 'sort_order' => 3]);

        // 2 products calculated by weight
        Product::create([
            'name' => 'Wireless Bluetooth Earbuds',
            'slug' => Str::slug('Wireless Bluetooth Earbuds'),
            'description' => 'Compact wireless earbuds with noise cancellation and 24-hour battery life.',
            'price' => 49.99,
            'stock' => 50,
            'weight' => 0.15,
            'length_cm' => 8.00,
            'width_cm' => 6.00,
            'height_cm' => 3.00,
            'shipping_calc_type' => 'weight',
            'category_id' => $electronics->id,
        ]);

        Product::create([
            'name' => 'Cotton Graphic T-Shirt',
            'slug' => Str::slug('Cotton Graphic T-Shirt'),
            'description' => 'Soft 100% cotton t-shirt with printed graphic design.',
            'price' => 19.99,
            'stock' => 80,
            'weight' => 0.25,
            'length_cm' => 30.00,
            'width_cm' => 25.00,
            'height_cm' => 3.00,
            'shipping_calc_type' => 'weight',
            'category_id' => $clothing->id,
        ]);

        // 3 products calculated by dimensions (centimeters)
        Product::create([
            'name' => 'Standing Desk Lamp',
            'slug' => Str::slug('Standing Desk Lamp'),
            'description' => 'Adjustable LED desk lamp with multiple brightness levels and USB charging port.',
            'price' => 34.50,
            'stock' => 50,
            'weight' => 1.20,
            'length_cm' => 45.00,
            'width_cm' => 15.00,
            'height_cm' => 15.00,
            'shipping_calc_type' => 'dimensions',
            'category_id' => $homeKitchen->id,
        ]);

        Product::create([
            'name' => '27-inch Monitor',
            'slug' => Str::slug('27-inch Monitor'),
            'description' => '27-inch IPS monitor with 4K resolution and HDR support.',
            'price' => 299.99,
            'stock' => 30,
            'weight' => 5.50,
            'length_cm' => 65.00,
            'width_cm' => 45.00,
            'height_cm' => 20.00,
            'shipping_calc_type' => 'dimensions',
            'category_id' => $electronics->id,
        ]);

        Product::create([
            'name' => 'Stainless Steel Cookware Set',
            'slug' => Str::slug('Stainless Steel Cookware Set'),
            'description' => '10-piece stainless steel cookware set with glass lids.',
            'price' => 129.99,
            'stock' => 40,
            'weight' => 8.00,
            'length_cm' => 55.00,
            'width_cm' => 35.00,
            'height_cm' => 30.00,
            'shipping_calc_type' => 'dimensions',
            'category_id' => $homeKitchen->id,
        ]);
    }
}
