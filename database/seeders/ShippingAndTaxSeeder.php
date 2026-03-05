<?php

namespace Database\Seeders;

use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use App\Models\TaxSetting;
use Illuminate\Database\Seeder;

class ShippingAndTaxSeeder extends Seeder
{
    public function run(): void
    {
        // Shipping settings
        ShippingSetting::create([
            'express_post_fee' => 15.00,
            'registered_post_fee' => 8.00,
            'insurance_fee' => 5.00,
            'free_shipping_threshold' => 200.00,
            'store_country' => 'Philippines',
            'store_state' => 'Metro Manila',
            'store_city' => 'Makati',
        ]);

        // Shipping zones
        ShippingZone::create([
            'zone_type' => 'own_city',
            'enabled' => true,
            'base_rate' => 50.00,
            'per_kg_rate' => 10.00,
            'per_cbm_rate' => 100.00,
        ]);

        ShippingZone::create([
            'zone_type' => 'own_state',
            'enabled' => true,
            'base_rate' => 80.00,
            'per_kg_rate' => 15.00,
            'per_cbm_rate' => 150.00,
        ]);

        ShippingZone::create([
            'zone_type' => 'own_country',
            'enabled' => true,
            'base_rate' => 120.00,
            'per_kg_rate' => 25.00,
            'per_cbm_rate' => 250.00,
        ]);

        ShippingZone::create([
            'zone_type' => 'other_country',
            'enabled' => false,
            'base_rate' => 500.00,
            'per_kg_rate' => 80.00,
            'per_cbm_rate' => 800.00,
        ]);

        // Tax settings
        TaxSetting::create([
            'tax_enabled' => true,
            'tax_rate' => 12.00,
            'tax_display_mode' => 'exclusive',
            'tax_name' => 'VAT',
        ]);
    }
}
