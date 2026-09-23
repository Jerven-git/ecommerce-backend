<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plan = config('subscriptions.default_plan');

        SubscriptionPlan::query()->updateOrCreate(
            ['slug' => $plan['slug']],
            [
                'name' => $plan['name'],
                'interval' => $plan['interval'],
                'price_cents' => $plan['price_cents'],
                'setup_fee_cents' => $plan['setup_fee_cents'],
                'features' => $plan['features'],
                'is_active' => true,
            ]
        );
    }
}
