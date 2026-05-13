<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Currency>
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper($this->faker->unique()->lexify('???'));

        return [
            'code' => $code,
            'name' => $this->faker->words(2, true),
            'symbol' => $this->faker->randomElement(['$', '€', '£', '¥', '₱']),
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'rate' => $this->faker->randomFloat(8, 0.5, 2.0),
            'is_base' => false,
            'is_enabled' => true,
            'sort_order' => 0,
        ];
    }

    public function base(): static
    {
        return $this->state(fn () => [
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate' => 1,
            'is_base' => true,
        ]);
    }
}
