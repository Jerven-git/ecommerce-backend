<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        ]);

        $settings = TaxSetting::first();

        if (!$settings) {
            $settings = TaxSetting::create([]);
        }

        $result = $settings->calculateCartTax($validated['items']);

        return response()->json($result);
    }
}
