<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class DefaultStoreSeeder extends Seeder
{
    public function run(): void
    {
        Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            [
                'name' => 'Default Store',
                'status' => 'active',
            ]
        );
    }
}
