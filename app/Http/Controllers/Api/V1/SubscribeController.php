<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Engagement\Application\NewsletterSubscription;
use Illuminate\Http\Request;

class SubscribeController extends Controller
{
    public function __construct(private readonly NewsletterSubscription $subscriptions) {}

    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'source' => 'nullable|string|in:welcome_popup,newsletter',
        ]);

        $source = $request->input('source', 'welcome_popup');
        $result = $this->subscriptions->subscribe($request->email, $source);

        return response()->json([
            'message' => $result['message'],
            'discount_code' => $result['discount_code'],
        ], $result['created'] ? 201 : 200);
    }
}
