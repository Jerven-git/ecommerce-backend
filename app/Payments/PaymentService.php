<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;

class PaymentService
{
    public function createPending(Order $order, string $provider, array $init): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'provider' => $provider,
            'provider_ref' => $init['provider_ref'] ?? null,
            'status' => 'pending',
            'amount' => (int) round($order->total_amount * 100),
            'currency' => strtoupper($order->currency ?? 'USD'),
            'meta' => $init,
        ]);
    }

    public function markPaid(Payment $payment): void
    {
        $payment->update(['status' => 'paid']);
        // Also update Order status if you want:
        // $payment->order->update(['status' => 'paid']);
    }
}
