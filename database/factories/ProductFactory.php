<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            // The products table requires a unique, non-null slug. Append a
            // random suffix so concurrent factory calls in a single test
            // can't collide on the same generated name.
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('######'),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 5, 500),
            'stock' => fake()->numberBetween(0, 100),
            'weight' => fake()->randomFloat(2, 0.1, 10),
            'category' => 'general',
            'is_active' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(['stock' => 0]);
    }

    public function backorderable(): static
    {
        return $this->state([
            'allow_backorder' => true,
            'backorder_charge_policy' => 'charged_later',
        ]);
    }
}
