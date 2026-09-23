<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            // Factory default = a usable tenant (comped keeps existing tests
            // flowing). Real stores created by the app default to 'unsubscribed'
            // via the column default; tests build gated stores with ->unsubscribed().
            'subscription_status' => 'comped',
            'default_currency_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_status' => \App\Models\Store::SUBSCRIPTION_UNSUBSCRIBED,
            'subscribed_at' => null,
            'subscription_expires_at' => null,
        ]);
    }

    /**
     * A custom domain that has passed DNS verification, and so participates in
     * host resolution and certificate issuance.
     */
    public function withVerifiedDomain(string $domain): static
    {
        return $this->state(fn (array $attributes) => [
            'domain' => $domain,
            'domain_verified_at' => now(),
        ]);
    }

    /**
     * A claimed but unproven domain: stored, yet inert.
     */
    public function withUnverifiedDomain(string $domain): static
    {
        return $this->state(fn (array $attributes) => [
            'domain' => $domain,
            'domain_verified_at' => null,
        ]);
    }
}
