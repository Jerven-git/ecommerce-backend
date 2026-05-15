<?php

namespace Database\Seeders;

use App\Models\GiftCardDenomination;
use Illuminate\Database\Seeder;

class GiftCardDenominationSeeder extends Seeder
{
    public function run(): void
    {
        $denominations = [
            ['amount' => '25.00',  'label' => null,        'sort_order' => 0],
            ['amount' => '50.00',  'label' => 'Popular',   'sort_order' => 1],
            ['amount' => '100.00', 'label' => 'Great gift', 'sort_order' => 2],
            ['amount' => '200.00', 'label' => null,        'sort_order' => 3],
        ];

        foreach ($denominations as $data) {
            GiftCardDenomination::firstOrCreate(
                ['amount' => $data['amount']],
                [
                    'label' => $data['label'],
                    'is_enabled' => true,
                    'sort_order' => $data['sort_order'],
                ]
            );
        }

        $this->command->info('Gift card denominations seeded.');
    }
}
