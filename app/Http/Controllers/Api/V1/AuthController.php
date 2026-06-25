<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\TwoFactorCodeMail;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Step 1: Verify credentials and send 2FA code via email.
     */
    public function login(Request $request)
    {
        $this->ensureSession($request);

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();

        if (! $user || ! $user->isAdminLike()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials or not authorized as admin.'],
            ]);
        }

        if ($user->isDisabled()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Generate and send 2FA code
        $this->generateAndSendCode($user);

        // Log out — user is NOT authenticated until 2FA is verified
        Auth::logout();

        // Store pending 2FA state in session
        $request->session()->put('two_factor_user_id', $user->id);
        $request->session()->put('two_factor_expires_at', now()->addMinutes(10)->timestamp);

        return response()->json([
            'message' => 'Verification code sent to your email.',
            'two_factor_required' => true,
        ]);
    }

    /**
     * Step 2: Verify the 2FA code and complete login.
     */
    public function verifyTwoFactor(Request $request)
    {
        $this->ensureSession($request);

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $userId = $request->session()->get('two_factor_user_id');
        $expiresAt = $request->session()->get('two_factor_expires_at');

        if (! $userId || ! $expiresAt || now()->timestamp > $expiresAt) {
            return response()->json([
                'message' => 'Your verification session has expired. Please log in again.',
            ], 422);
        }

        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'message' => 'User not found. Please log in again.',
            ], 422);
        }

        if (! $user->isAdminLike()) {
            $request->session()->forget(['two_factor_user_id', 'two_factor_expires_at']);

            return response()->json([
                'message' => 'This account is not authorized for admin access.',
            ], 403);
        }

        if ($user->isDisabled()) {
            $request->session()->forget(['two_factor_user_id', 'two_factor_expires_at']);

            return response()->json([
                'message' => 'Your account has been disabled. Contact a super admin.',
                'code' => 'account_disabled',
            ], 403);
        }

        // Find the latest unused, unexpired code
        $twoFactorCode = TwoFactorCode::where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $twoFactorCode || ! Hash::check($request->code, $twoFactorCode->code)) {
            return response()->json([
                'message' => 'Invalid verification code.',
            ], 422);
        }

        // Mark code as used
        $twoFactorCode->update(['used_at' => now()]);

        // Clean up session 2FA data
        $request->session()->forget(['two_factor_user_id', 'two_factor_expires_at']);

        // Fully authenticate the user
        Auth::login($user);
        $request->session()->regenerate();

        // Track session creation time for 24h expiry
        $request->session()->put('session_created_at', now()->timestamp);

        return response()->json([
            'message' => 'Login successful',
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * Resend the 2FA code.
     */
    public function resendTwoFactor(Request $request)
    {
        $this->ensureSession($request);

        $userId = $request->session()->get('two_factor_user_id');

        if (! $userId) {
            return response()->json([
                'message' => 'No pending verification found. Please log in again.',
            ], 422);
        }

        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'message' => 'User not found. Please log in again.',
            ], 422);
        }

        if (! $user->isAdminLike()) {
            $request->session()->forget(['two_factor_user_id', 'two_factor_expires_at']);

            return response()->json([
                'message' => 'This account is not authorized for admin access.',
            ], 403);
        }

        if ($user->isDisabled()) {
            $request->session()->forget(['two_factor_user_id', 'two_factor_expires_at']);

            return response()->json([
                'message' => 'Your account has been disabled. Contact a super admin.',
                'code' => 'account_disabled',
            ], 403);
        }

        // Generate and send a new code
        $this->generateAndSendCode($user);

        // Reset session expiry
        $request->session()->put('two_factor_expires_at', now()->addMinutes(10)->timestamp);

        return response()->json([
            'message' => 'A new verification code has been sent to your email.',
        ]);
    }

    /**
     * Logout user.
     */
    public function logout(Request $request)
    {
        $this->ensureSession($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request)
    {
        $user = $request->user();

        \Log::channel('single')->info('IMP_DEBUG /user', [
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'cookie_session' => $request->cookies->has(config('session.cookie')),
            'has_user' => (bool) $user,
            'user_id' => $user?->id,
            'session_keys' => $request->hasSession() ? array_keys($request->session()->all()) : [],
            'web_guard_check' => \Illuminate\Support\Facades\Auth::guard('web')->check(),
        ]);

        if (! $user) {
            return response()->json(['user' => null]);
        }

        $manager = app(\Lab404\Impersonate\Services\ImpersonateManager::class);
        $isImpersonating = $manager->isImpersonating();
        $impersonator = null;

        if ($isImpersonating) {
            $impersonatorId = session(config('laravel-impersonate.session_key'));
            $impersonatorUser = User::find($impersonatorId);
            if ($impersonatorUser) {
                $impersonator = $this->serializeUser($impersonatorUser);
            }
        }

        return response()->json([
            'user' => array_merge($this->serializeUser($user), [
                'is_impersonating' => $isImpersonating,
                'impersonator' => $impersonator,
            ]),
        ]);
    }

    private function serializeUser(User $user): array
    {
        $user->loadMissing(['roles', 'store']);
        $roles = $user->roleNames();

        return array_merge($user->toArray(), [
            'roles' => $roles,
            'is_admin' => in_array('admin', $roles, true) || in_array('super_admin', $roles, true),
            'is_super_admin' => in_array('super_admin', $roles, true),
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'slug' => $user->store->slug,
                'domain' => $user->store->domain,
            ] : null,
        ]);
    }

    /**
     * Generate a 6-digit code, store hashed, and email it.
     */
    private function generateAndSendCode(User $user): void
    {
        // Invalidate any existing unused codes
        TwoFactorCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->send(new TwoFactorCodeMail($code, $user->name));
    }

    private function ensureSession(Request $request): void
    {
        if ($request->hasSession()) {
            return;
        }

        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
    }
}
