<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    public function index(Request $request)
    {
        $query = Discount::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search by code or description
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        $discounts = $query->paginate($request->input('per_page', 15));

        return response()->json($discounts);
    }

    public function show($id)
    {
        $discount = Discount::findOrFail($id);

        return response()->json(['data' => $discount]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:255',
                Rule::unique('discounts', 'code')->where('store_id', app(CurrentStore::class)->id()),
            ],
            'description' => 'nullable|string',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'valid_until' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        // Additional validation for percentage
        if ($validated['type'] === 'percentage' && $validated['value'] > 100) {
            return response()->json([
                'message' => 'Percentage discount cannot exceed 100%',
            ], 422);
        }

        $discount = Discount::create($validated);

        return response()->json([
            'message' => 'Discount created successfully',
            'data' => $discount,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $discount = Discount::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('discounts', 'code')
                    ->where('store_id', app(CurrentStore::class)->id())
                    ->ignore($id),
            ],
            'description' => 'nullable|string',
            'type' => 'sometimes|in:percentage,fixed',
            'value' => 'sometimes|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'valid_until' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        // Additional validation for percentage
        if (isset($validated['type']) && $validated['type'] === 'percentage' && isset($validated['value']) && $validated['value'] > 100) {
            return response()->json([
                'message' => 'Percentage discount cannot exceed 100%',
            ], 422);
        }

        $discount->update($validated);

        return response()->json([
            'message' => 'Discount updated successfully',
            'data' => $discount,
        ]);
    }

    public function destroy($id)
    {
        $discount = Discount::findOrFail($id);
        $discount->delete();

        return response()->json([
            'message' => 'Discount deleted successfully',
        ]);
    }

    public function validate(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'order_amount' => 'required|numeric|min:0',
        ]);

        $discount = Discount::where('code', $request->code)->first();

        if (! $discount) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid discount code',
            ], 404);
        }

        if (! $discount->isValid($request->order_amount)) {
            return response()->json([
                'valid' => false,
                'message' => 'This discount code is not valid for your order',
            ], 422);
        }

        $discountAmount = $discount->calculateDiscount($request->order_amount);

        return response()->json([
            'valid' => true,
            'discount' => $discount,
            'discount_amount' => $discountAmount,
            'final_amount' => $request->order_amount - $discountAmount,
        ]);
    }
}
