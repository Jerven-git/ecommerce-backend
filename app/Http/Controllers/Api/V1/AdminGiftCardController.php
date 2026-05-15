<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardDenomination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminGiftCardController extends Controller
{
    // --- Denominations ---

    public function denominationIndex(): JsonResponse
    {
        $denominations = GiftCardDenomination::orderBy('sort_order')->orderBy('amount')->get();

        return response()->json(['data' => $denominations]);
    }

    public function denominationStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:99999',
            'label' => 'nullable|string|max:100',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $denomination = GiftCardDenomination::create($validated);

        return response()->json(['data' => $denomination], 201);
    }

    public function denominationUpdate(Request $request, GiftCardDenomination $denomination): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:1|max:99999',
            'label' => 'nullable|string|max:100',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $denomination->update($validated);

        return response()->json(['data' => $denomination->fresh()]);
    }

    public function denominationDestroy(GiftCardDenomination $denomination): JsonResponse
    {
        $denomination->delete();

        return response()->json(['message' => 'Denomination deleted.']);
    }

    // --- Issued Gift Cards ---

    public function index(Request $request): JsonResponse
    {
        $query = GiftCard::with('order')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhere('recipient_email', 'like', "%{$term}%")
                    ->orWhere('purchaser_email', 'like', "%{$term}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'recipient_email' => 'required|email',
            'recipient_name' => 'nullable|string|max:255',
            'purchaser_name' => 'required|string|max:255',
            'purchaser_email' => 'required|email',
            'message' => 'nullable|string|max:1000',
            'currency' => 'nullable|string|size:3',
        ]);

        $card = GiftCard::create([
            'code' => GiftCard::generateCode(),
            'original_amount' => $validated['amount'],
            'balance' => $validated['amount'],
            'currency' => strtoupper($validated['currency'] ?? 'USD'),
            'purchaser_name' => $validated['purchaser_name'],
            'purchaser_email' => $validated['purchaser_email'],
            'recipient_name' => $validated['recipient_name'] ?? null,
            'recipient_email' => $validated['recipient_email'],
            'message' => $validated['message'] ?? null,
            'status' => GiftCard::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        return response()->json(['data' => $card], 201);
    }

    public function update(Request $request, GiftCard $giftCard): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:active,partially_used,fully_used,void,pending_payment',
            'balance' => 'sometimes|numeric|min:0',
        ]);

        $giftCard->update($validated);

        return response()->json(['data' => $giftCard->fresh()]);
    }
}
