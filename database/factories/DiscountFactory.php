<?php

namespace Database\Factories;

use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('????-????')),
            'description' => fake()->sentence(),
            'type' => 'percentage',
            'value' => fake()->numberBetween(5, 50),
            'min_order_amount' => 0,
            'is_active' => true,
        ];
    }

    public function fixed(float $value = 10): static
    {
        return $this->state([
            'type' => 'fixed',
            'value' => $value,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'valid_until' => now()->subDay(),
        ]);
    }
}
