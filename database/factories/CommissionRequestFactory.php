<?php

namespace Database\Factories;

use App\Models\CommissionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CommissionRequest>
 */
class CommissionRequestFactory extends Factory
{
    protected $model = CommissionRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
            'customer_phone' => $this->faker->phoneNumber(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'budget_range' => $this->faker->randomElement(['Under $500', '$500–$1000', '$1000–$2500', '$2500+']),
            'preferred_medium' => $this->faker->randomElement(['Oil on canvas', 'Watercolor', 'Acrylic', 'Charcoal']),
            'preferred_size' => $this->faker->randomElement(['A3', 'A2', '60x90cm', '90x120cm']),
            'deadline' => $this->faker->optional()->dateTimeBetween('+2 weeks', '+6 months'),
            'reference_image_url' => null,
            'status' => 'pending',
            'admin_notes' => null,
        ];
    }
}
