<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Engagement\Application\ContactDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(private readonly ContactDelivery $contacts) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email:rfc,dns|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            'recaptcha_token' => 'required|string',
        ]);

        $this->contacts->send($validated);

        return response()->json([
            'message' => 'Your message has been sent successfully.',
        ]);
    }
}
