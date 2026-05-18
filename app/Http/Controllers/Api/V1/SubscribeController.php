<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\SiteConfig;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscribeController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'source' => 'nullable|string|in:welcome_popup,newsletter',
        ]);

        $email = strtolower(trim($request->email));
        $source = $request->input('source', 'welcome_popup');

        // Check if already subscribed
        $existing = Subscriber::where('email', $email)->first();
        if ($existing) {
            return response()->json([
                'message' => 'You\'re already subscribed!',
                'discount_code' => $source === 'welcome_popup' ? $existing->discount_code_sent : null,
            ]);
        }

        $discountCode = null;

        // Only generate discount codes for welcome popup subscribers
        if ($source === 'welcome_popup') {
            $config = SiteConfig::forDefaultStore();

            if ($config?->welcome_popup_discount_id) {
                $template = Discount::find($config->welcome_popup_discount_id);

                if ($template && $template->is_active) {
                    $uniqueCode = $this->generateUniqueCode();

                    Discount::create([
                        'code' => $uniqueCode,
                        'description' => 'Welcome popup discount for '.$email,
                        'type' => $template->type,
                        'value' => $template->value,
                        'min_order_amount' => $template->min_order_amount,
                        'max_uses' => 1,
                        'is_active' => true,
                        'valid_until' => $template->valid_until,
                    ]);

                    $discountCode = $uniqueCode;
                }
            }
        }

        Subscriber::create([
            'email' => $email,
            'source' => $source,
            'discount_code_sent' => $discountCode,
        ]);

        return response()->json([
            'message' => 'Subscribed successfully!',
            'discount_code' => $discountCode,
        ], 201);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'WELCOME-'.strtoupper(Str::random(6));
        } while (Discount::where('code', $code)->exists());

        return $code;
    }
}
