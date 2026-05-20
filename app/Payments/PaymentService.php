<?php

namespace App\Payments;

use App\Events\PaymentConfirmed;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Payments\Contracts\PaymentServiceInterface;
use App\Payments\DTOs\PaymentInitData;
use App\Support\Tenancy\CurrentStore;

class PaymentService implements PaymentServiceInterface
{
    public const PAYMENT_STATUS_PENDING = 'pending';

    public const PAYMENT_STATUS_PAID = 'paid';

    public function createPending(Order $order, string $provider, array $init): Payment
    {
        $data = PaymentInitData::fromArray($init);

        return Payment::create([
            'order_id' => $order->id,
            'provider' => $provider,
            'provider_ref' => $data->providerRef,
            'status' => self::PAYMENT_STATUS_PENDING,
            'amount' => $this->toCents($order->total_amount),
            'currency' => strtoupper($order->currency ?: 'USD'),
            'meta' => $data->toArray(),
        ]);
    }

    public function markPaid(Payment $payment): void
    {
        if ($payment->status !== self::PAYMENT_STATUS_PAID) {
            $payment->update(['status' => self::PAYMENT_STATUS_PAID]);
        }

        if (! $payment->order_id) {
            return;
        }

        // Payment confirmations arrive from contexts with an unreliable tenant:
        // provider webhooks have no CurrentStore at all, and PayPal return URLs
        // resolve CurrentStore from whatever host the customer landed on. Pin it
        // to the order's own store (read without the scope so it's found
        // regardless of the ambient tenant) so the PaymentConfirmed listeners —
        // status update, stock deduction, confirmation emails — all operate on
        // the correct store.
        $order = Order::withoutGlobalScope(StoreScope::class)->find($payment->order_id);
        if ($order && $order->store_id) {
            $store = Store::find($order->store_id);
            if ($store) {
                app(CurrentStore::class)->set($store);
            }
        }

        PaymentConfirmed::dispatch($payment);
    }

    private function toCents($amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');

        return (int) str_replace('.', '', $normalized);
    }
}
