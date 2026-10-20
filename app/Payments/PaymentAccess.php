<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentAccess
{
    private const SESSION_KEY = 'payment_access';

    private const MAX_SESSION_PAYMENTS = 20;

    public function grant(Request $request, Payment $payment): void
    {
        if (! $request->hasSession() || ! $payment->public_token) {
            return;
        }

        $access = (array) $request->session()->get(self::SESSION_KEY, []);
        $access[(string) $payment->id] = $payment->public_token;

        $request->session()->put(
            self::SESSION_KEY,
            array_slice($access, -self::MAX_SESSION_PAYMENTS, null, true),
        );
    }

    public function allows(Request $request, Payment $payment): bool
    {
        $user = $request->user();
        $order = $payment->order;

        if ($user?->isSuperAdmin()) {
            return true;
        }

        if ($user?->isAdminLike()
            && $user->store_id
            && $order
            && (int) $user->store_id === (int) $order->store_id) {
            return true;
        }

        if (! $payment->public_token) {
            return false;
        }

        $suppliedToken = $request->query('payment_token')
            ?: $request->query('token')
            ?: $request->header('X-Payment-Token');
        if (is_string($suppliedToken) && hash_equals($payment->public_token, $suppliedToken)) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        $sessionToken = $request->session()->get(self::SESSION_KEY.'.'.$payment->id);

        return is_string($sessionToken) && hash_equals($payment->public_token, $sessionToken);
    }
}
