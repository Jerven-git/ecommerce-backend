<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaxRule;
use App\Models\TaxSetting;
use Illuminate\Http\Request;

class TaxSettingsController extends Controller
{
    public function show()
    {
        $settings = TaxSetting::first();

        if (!$settings) {
            $settings = TaxSetting::create([]);
        }

        return response()->json(['data' => $settings]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'tax_enabled' => 'nullable|boolean',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_display_mode' => 'nullable|in:inclusive,exclusive',
            'tax_name' => 'nullable|string|max:50',
            'default_display_country' => 'nullable|string|max:100',
            'default_display_state' => 'nullable|string|max:100',
        ]);

        $settings = TaxSetting::first();

        if (!$settings) {
            $settings = TaxSetting::create($validated);
        } else {
            $settings->update($validated);
        }

        return response()->json([
            'message' => 'Tax settings updated successfully',
            'data' => $settings
        ]);
    }

    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);

        $settings = TaxSetting::first();

        if (!$settings) {
            $settings = TaxSetting::create([]);
        }

        $result = $settings->calculateTax($validated['price']);

        return response()->json($result);
    }

    public function calculateCart(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_amount' => 'nullable|numeric|min:0',
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
        ]);

        $settings = TaxSetting::first();

        if (!$settings) {
            $settings = TaxSetting::create([]);
        }

        $discountAmount = (float) ($validated['discount_amount'] ?? 0);
        $shippingAmount = (float) ($validated['shipping_amount'] ?? 0);
        $country = $validated['country'] ?? null;
        $state = $validated['state'] ?? null;

        // Resolve regional tax rate
        $resolved = $settings->resolveForRegion($country, $state);

        // Use full order calculation when discount or shipping is provided
        if ($discountAmount > 0 || $shippingAmount > 0) {
            $result = $settings->calculateOrderTotals(
                $validated['items'],
                $discountAmount,
                $shippingAmount,
                $resolved['rate'],
                $resolved['name'],
                $resolved['mode']
            );
        } else {
            $result = $settings->calculateOrderTotals(
                $validated['items'],
                0,
                0,
                $resolved['rate'],
                $resolved['name'],
                $resolved['mode']
            );
        }

        $result['rule_id'] = $resolved['rule_id'];
        $result['region_label'] = $resolved['region_label'];
        $result['has_regional_rules'] = TaxRule::where('enabled', true)->exists();

        return response()->json($result);
    }

    /**
     * Resolve the applicable tax rate for a given region.
     * Used by the frontend to display prices with the correct tax.
     */
    public function resolve(Request $request)
    {
        $validated = $request->validate([
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
        ]);

        $settings = TaxSetting::first();

        if (!$settings) {
            $settings = TaxSetting::create([]);
        }

        $resolved = $settings->resolveForRegion(
            $validated['country'] ?? null,
            $validated['state'] ?? null
        );

        return response()->json($resolved);
    }
}
