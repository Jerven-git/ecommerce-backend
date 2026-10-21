<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CommerceSettings\Application\GiftCardManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public function __construct(private readonly GiftCardManager $giftCards) {}

    public function denominations(): JsonResponse
    {
        $this->abortIfModuleDisabled();

        return response()->json(['data' => $this->giftCards->enabledDenominations()]);
    }

    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:20',
        ]);

        $card = $this->giftCards->findUsable($request->input('code'));

        if (! $card || ! $card->isUsable()) {
            return response()->json([
                'message' => 'Invalid or expired gift card code.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'code' => $card->code,
                'balance' => (float) $card->balance,
                'currency' => $card->currency,
            ],
        ]);
    }

    private function abortIfModuleDisabled(): void
    {
        if (! $this->giftCards->moduleEnabled()) {
            abort(404);
        }
    }
}
