<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => fake()->phoneNumber(),
            'shipping_address' => fake()->address() ?: '123 Main St',
            'delivery_method' => 'delivery',
            'country' => 'US',
            'state' => 'CA',
            'city' => 'Los Angeles',
            'total_amount' => fake()->randomFloat(2, 10, 500),
            'subtotal' => fake()->randomFloat(2, 10, 500),
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'status' => 'pending',
        ];
    }

    public function processing(): static
    {
        return $this->state(['status' => 'processing']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }
}
