<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Note: this seeder intentionally does NOT use the WithoutModelEvents
     * trait. Catalog models rely on the BelongsToStore `creating` hook to
     * auto-fill `store_id` from CurrentStore — suppressing model events
     * leaves `store_id` null and the NOT NULL constraint fails.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

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
        ]);
    }
}
