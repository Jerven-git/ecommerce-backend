<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use App\Services\ShippingCalculator;
use Illuminate\Http\Request;

class ShippingSettingsController extends Controller
{
    public function show()
    {
        $settings = ShippingSetting::first();
        $zones = ShippingZone::all();

        if (!$settings) {
            $settings = ShippingSetting::create([]);
        }

        return response()->json([
            'data' => array_merge(
                $settings->toArray(),
                ['zones' => $zones]
            )
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'express_post_fee' => 'nullable|numeric|min:0',
            'registered_post_fee' => 'nullable|numeric|min:0',
            'insurance_fee' => 'nullable|numeric|min:0',
            'free_shipping_threshold' => 'nullable|numeric|min:0',
            'store_country' => 'nullable|string|max:255',
            'store_state' => 'nullable|string|max:255',
            'store_city' => 'nullable|string|max:255',
            'zones' => 'nullable|array',
            'zones.*.zone_type' => 'required|in:own_city,own_state,own_country,other_city,other_state,other_country',
            'zones.*.enabled' => 'required|boolean',
            'zones.*.base_rate' => 'required|numeric|min:0',
            'zones.*.per_kg_rate' => 'nullable|numeric|min:0',
        ]);

        $settings = ShippingSetting::first();

        if (!$settings) {
            $settings = ShippingSetting::create($validated);
        } else {
            $settings->update($validated);
        }

        // Update or create shipping zones
        if (isset($validated['zones'])) {
            foreach ($validated['zones'] as $zoneData) {
                ShippingZone::updateOrCreate(
                    ['zone_type' => $zoneData['zone_type']],
                    [
                        'enabled' => $zoneData['enabled'],
                        'base_rate' => $zoneData['base_rate'],
                        'per_kg_rate' => $zoneData['per_kg_rate'] ?? 0,
                    ]
                );
            }
        }

        return response()->json([
            'message' => 'Shipping settings updated successfully',
            'data' => $settings
        ]);
    }

    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'country' => 'required|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0',
            'order_amount' => 'required|numeric|min:0',
            'options' => 'nullable|array',
            'options.*' => 'in:express_post,registered_post,insurance',
        ]);

        $calculator = new ShippingCalculator();
        $result = $calculator->calculateShipping($validated);

        return response()->json($result);
    }
}