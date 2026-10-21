<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardDenomination;
use App\Modules\CommerceSettings\Application\GiftCardManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminGiftCardController extends Controller
{
    public function __construct(private readonly GiftCardManager $giftCards) {}

    // --- Denominations ---

    public function denominationIndex(): JsonResponse
    {
        return response()->json(['data' => $this->giftCards->denominations()]);
    }

    public function denominationStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:99999',
            'label' => 'nullable|string|max:100',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $denomination = $this->giftCards->createDenomination($validated);

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

        return response()->json(['data' => $this->giftCards->updateDenomination($denomination, $validated)]);
    }

    public function denominationDestroy(GiftCardDenomination $denomination): JsonResponse
    {
        $this->giftCards->deleteDenomination($denomination);

        return response()->json(['message' => 'Denomination deleted.']);
    }

    // --- Issued Gift Cards ---

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->giftCards->paginate($request->only(['status', 'search', 'per_page'])));
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

        $card = $this->giftCards->issue($validated);

        return response()->json(['data' => $card], 201);
    }

    public function update(Request $request, GiftCard $giftCard): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:active,partially_used,fully_used,void,pending_payment',
            'balance' => 'sometimes|numeric|min:0',
        ]);

        return response()->json(['data' => $this->giftCards->update($giftCard, $validated)]);
    }
}
