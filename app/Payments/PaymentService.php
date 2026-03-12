<?php

namespace App\Payments;

use App\Events\PaymentConfirmed;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Contracts\PaymentServiceInterface;
use App\Payments\DTOs\PaymentInitData;

class PaymentService implements PaymentServiceInterface
{
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';

    public function createPending(Order $order, string $provider, array $init): Payment
    {
        $data = PaymentInitData::fromArray($init);

        return Payment::create([
            'order_id'      => $order->id,
            'provider'      => $provider,
            'provider_ref'  => $data->providerRef,
            'status'        => self::PAYMENT_STATUS_PENDING,
            'amount'        => $this->toCents($order->total_amount),
            'currency'      => strtoupper($order->currency ?: 'USD'),
            'meta'          => $data->toArray(),
        ]);
    }

    public function markPaid(Payment $payment): void
    {
        if ($payment->status !== self::PAYMENT_STATUS_PAID) {
            $payment->update(['status' => self::PAYMENT_STATUS_PAID]);
        }

        if (!$payment->order_id) {
            return;
        }

        PaymentConfirmed::dispatch($payment);
    }

    private function toCents($amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        return (int) str_replace('.', '', $normalized);
    }
}
