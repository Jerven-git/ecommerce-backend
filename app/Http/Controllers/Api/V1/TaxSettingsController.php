<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CommerceSettings\Application\TaxConfiguration;
use Illuminate\Http\Request;

class TaxSettingsController extends Controller
{
    public function __construct(private readonly TaxConfiguration $tax) {}

    public function show()
    {
        return response()->json(['data' => $this->tax->settings()]);
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

        $settings = $this->tax->update($validated);

        return response()->json([
            'message' => 'Tax settings updated successfully',
            'data' => $settings,
        ]);
    }

    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);

        return response()->json($this->tax->calculate((float) $validated['price']));
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

        return response()->json($this->tax->calculateCart($validated));
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

        return response()->json($this->tax->resolve(
            $validated['country'] ?? null,
            $validated['state'] ?? null,
        ));
    }
}
