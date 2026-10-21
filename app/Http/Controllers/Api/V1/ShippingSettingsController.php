<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CommerceSettings\Application\ShippingConfiguration;
use Illuminate\Http\Request;

class ShippingSettingsController extends Controller
{
    public function __construct(private readonly ShippingConfiguration $shipping) {}

    public function show()
    {
        return response()->json([
            'data' => $this->shipping->get(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'express_post_fee' => 'nullable|numeric|min:0',
            'registered_post_fee' => 'nullable|numeric|min:0',
            'insurance_fee' => 'nullable|numeric|min:0',
            'insurance_rate_percent' => 'nullable|numeric|min:0|max:100',
            'insurance_min_fee' => 'nullable|numeric|min:0',
            'express_label' => 'nullable|string|max:100',
            'express_pricing_mode' => 'nullable|string|in:flat,weight_tiered',
            'express_weight_tiers' => 'nullable|array',
            'express_weight_tiers.*.max_weight_g' => 'required|numeric|min:1',
            'express_weight_tiers.*.rate' => 'required|numeric|min:0',
            'registered_label' => 'nullable|string|max:100',
            'insurance_label' => 'nullable|string|max:100',
            'free_shipping_threshold' => 'nullable|numeric|min:0',
            'store_country' => 'nullable|string|max:255',
            'store_state' => 'nullable|string|max:255',
            'store_city' => 'nullable|string|max:255',
            'zones' => 'nullable|array',
            'zones.*.zone_type' => 'required|in:own_city,own_state,own_country,other_country',
            'zones.*.enabled' => 'required|boolean',
            'zones.*.base_rate' => 'required|numeric|min:0',
            'zones.*.per_kg_rate' => 'nullable|numeric|min:0',
            'zones.*.per_cbm_rate' => 'nullable|numeric|min:0',
        ]);

        $settings = $this->shipping->update($validated);

        return response()->json([
            'message' => 'Shipping settings updated successfully',
            'data' => $settings,
        ]);
    }

    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0',
            'volume_cbm' => 'nullable|numeric|min:0',
            'order_amount' => 'required|numeric|min:0',
            'method' => 'nullable|string|in:standard,express,registered',
            'options' => 'nullable|array',
            'options.*' => 'in:insurance',
        ]);

        return response()->json($this->shipping->calculate($validated));
    }

    public function options()
    {
        return response()->json(['data' => $this->shipping->options()]);
    }

    public function zones()
    {
        return response()->json(['data' => $this->shipping->enabledZones()]);
    }
}
