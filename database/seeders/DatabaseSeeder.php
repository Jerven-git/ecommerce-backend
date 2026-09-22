<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * This is the single entry point — one command seeds everything:
     *   php artisan db:seed
     *
     * Every seeder called here is idempotent (firstOrCreate / updateOrCreate
     * / wipe-then-seed), so re-running never duplicates data.
     *
     * Standalone seeders NOT called here (run manually when needed):
     * - DashboardDemoSeeder is included below for local chart testing.
     * - OrderStressSeeder (65k perf-test orders) stays manual:
     *   php artisan db:seed --class=OrderStressSeeder
     *
     * Note: this seeder intentionally does NOT use the WithoutModelEvents
     * trait. Catalog models rely on the BelongsToStore `creating` hook to
     * auto-fill `store_id` from CurrentStore — suppressing model events
     * leaves `store_id` null and the NOT NULL constraint fails.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password')]
        );

        $this->call([
            CurrencySeeder::class,
            DefaultStoreSeeder::class,
        ]);

        // Pin CurrentStore to the default store so the BelongsToStore creating
        // hook auto-fills `store_id` on every record inserted by the catalog
        // seeders below.
        app(CurrentStore::class)->set(
            Store::query()->where('slug', Store::DEFAULT_SLUG)->firstOrFail()
        );

        $this->call([
            ProductSeeder::class,
            ShippingAndTaxSeeder::class,
            AdminSeeder::class,
            BlogSeeder::class,
            ServicesSeeder::class,
            GiftCardDenominationSeeder::class,
            // Demo orders for the admin dashboard Sales Analytics charts.
            // Wipes its own rows first — safe to re-run.
            DashboardDemoSeeder::class,
        ]);
    }
}
