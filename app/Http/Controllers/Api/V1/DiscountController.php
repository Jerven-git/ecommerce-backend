<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CommerceSettings\Application\DiscountManager;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    public function __construct(private readonly DiscountManager $discounts) {}

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'sort', 'order', 'per_page']);
        if ($request->has('is_active')) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return response()->json($this->discounts->paginate($filters));
    }

    public function show($id)
    {
        return response()->json(['data' => $this->discounts->find((int) $id)]);
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

        $discount = $this->discounts->create($validated);

        return response()->json([
            'message' => 'Discount created successfully',
            'data' => $discount,
        ], 201);
    }

    public function update(Request $request, $id)
    {
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

        $discount = $this->discounts->update((int) $id, $validated);

        return response()->json([
            'message' => 'Discount updated successfully',
            'data' => $discount,
        ]);
    }

    public function destroy($id)
    {
        $this->discounts->delete((int) $id);

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

        $discount = $this->discounts->byCode($request->code);

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
