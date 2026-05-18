<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardDenomination;
use App\Models\SiteConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public function denominations(): JsonResponse
    {
        $this->abortIfModuleDisabled();

        $denominations = GiftCardDenomination::enabled()->get();

        return response()->json(['data' => $denominations]);
    }

    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:20',
        ]);

        $code = strtoupper(trim($request->input('code')));
        $card = GiftCard::where('code', $code)->first();

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
        $config = SiteConfig::forDefaultStore();
        $modules = $config?->modules_enabled ?? [];
        $enabled = $modules['gift_cards'] ?? false;

        if (! $enabled) {
            abort(404);
        }
    }
}
