<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'USD', 'name' => 'US Dollar',          'symbol' => '$',   'position' => 'before', 'decimals' => 2, 'rate' => 1.00000000,   'base' => true,  'sort' => 0],
            ['code' => 'AUD', 'name' => 'Australian Dollar',  'symbol' => 'A$',  'position' => 'before', 'decimals' => 2, 'rate' => 1.50000000,   'base' => false, 'sort' => 10],
            ['code' => 'EUR', 'name' => 'Euro',               'symbol' => '€',   'position' => 'before', 'decimals' => 2, 'rate' => 0.92000000,   'base' => false, 'sort' => 20],
            ['code' => 'GBP', 'name' => 'British Pound',      'symbol' => '£',   'position' => 'before', 'decimals' => 2, 'rate' => 0.79000000,   'base' => false, 'sort' => 30],
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'position' => 'before', 'decimals' => 2, 'rate' => 1.64000000,   'base' => false, 'sort' => 40],
            ['code' => 'CAD', 'name' => 'Canadian Dollar',    'symbol' => 'C$',  'position' => 'before', 'decimals' => 2, 'rate' => 1.36000000,   'base' => false, 'sort' => 50],
            ['code' => 'JPY', 'name' => 'Japanese Yen',       'symbol' => '¥',   'position' => 'before', 'decimals' => 0, 'rate' => 152.00000000, 'base' => false, 'sort' => 60],
            ['code' => 'PHP', 'name' => 'Philippine Peso',    'symbol' => '₱',   'position' => 'before', 'decimals' => 2, 'rate' => 56.00000000,  'base' => false, 'sort' => 70],
        ];

        foreach ($rows as $r) {
            Currency::updateOrCreate(
                ['code' => $r['code']],
                [
                    'name' => $r['name'],
                    'symbol' => $r['symbol'],
                    'symbol_position' => $r['position'],
                    'decimal_places' => $r['decimals'],
                    'rate' => $r['rate'],
                    'is_base' => $r['base'],
                    'is_enabled' => true,
                    'sort_order' => $r['sort'],
                ],
            );
        }
    }
}
