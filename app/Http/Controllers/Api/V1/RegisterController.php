<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Modules\Identity\Application\Registration;
use App\Modules\Identity\Application\UserPresenter;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __construct(
        private readonly Registration $registration,
        private readonly UserPresenter $presenter,
    ) {}

    /**
     * Self-service signup: a prospect creates their account plus a brand-new
     * store in one atomic step, which is created gated (`unsubscribed`) until
     * they complete checkout. No auto-login — the platform's mandatory 2FA
     * login is a separate two-step flow (see §8.2 of SUBSCRIPTION_FEATURE.md).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->registration->register($validated);

        return response()->json([
            'message' => 'Account created. Log in to finish setting up your store.',
            'data' => $this->presenter->registration($user),
            'redirect' => '/subscribe',
        ], 201);
    }
}
