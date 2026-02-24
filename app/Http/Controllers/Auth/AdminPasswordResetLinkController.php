<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AdminPasswordResetLinkController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $isAdminLike = User::query()
            ->where('email', $request->email)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super_admin']))
            ->exists();

        // Prevent email enumeration
        if (! $isAdminLike) {
            return response()->json([
                'message' => __('If your email is in our system, you will receive a reset link.'),
            ]);
        }

        $status = Password::sendResetLink(['email' => $request->email]);

        return $status === Password::ResetLinkSent
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }
}