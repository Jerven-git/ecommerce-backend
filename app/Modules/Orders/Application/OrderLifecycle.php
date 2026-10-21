<?php

namespace App\Modules\Orders\Application;

use App\Mail\OrderCancellationMail;
use App\Models\Order;
use App\Payments\PaymentService;
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderLifecycle
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => ['processing'],
        'cancelled' => ['pending'],
        'backorder_awaiting_stock' => [],
        'backorder_notified' => [],
        'backorder_expired' => [],
        'backorder_cancelled' => [],
    ];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentService $payments,
    ) {}

    public function updateStatus(int $id, string $status): Order
    {
        $order = Order::findOrFail($id);

        if (! in_array($status, self::ALLOWED_TRANSITIONS[$order->status] ?? [], true)) {
            throw new OrderOperationException("Cannot transition from '{$order->status}' to '{$status}'");
        }

        if (in_array($status, ['cancelled', 'backorder_cancelled'], true)) {
            $payment = $order->payment;
            if ($payment && $payment->status === 'paid') {
                throw new OrderOperationException(
                    'Cannot cancel an order with a completed payment. Please undo the payment first.',
                );
            }
        }

        $order->forceFill(['status' => $status])->save();

        if ($status === 'cancelled') {
            DB::transaction(function () use ($order) {
                $payment = $order->payment;
                if ($payment && $payment->status === 'pending') {
                    $payment->update(['status' => 'failed']);
                }

                $this->orders->restoreStock($order);
            });

            $this->sendCancellationMail($order);
        }

        return $order->load('payment');
    }

    public function confirmPayment(int $id): Order
    {
        $order = $this->orders->findWithPayment($id);
        $payment = $order->payment;

        if (! $payment) {
            throw new OrderOperationException('No payment record found', 404);
        }
        if ($payment->status === 'paid') {
            throw new OrderOperationException('Payment already confirmed');
        }
        if ($payment->provider !== 'cash') {
            throw new OrderOperationException('Only cash payments can be manually confirmed');
        }

        $this->payments->markPaid($payment);

        return $order->fresh()->load('payment');
    }

    public function undoPayment(int $id): Order
    {
        $order = $this->orders->findWithPayment($id);
        $payment = $order->payment;

        if (! $payment) {
            throw new OrderOperationException('No payment record found', 404);
        }
        if ($payment->provider !== 'cash') {
            throw new OrderOperationException('Only cash payments can be undone');
        }
        if ($payment->status !== 'paid') {
            throw new OrderOperationException('Payment is not confirmed');
        }

        DB::transaction(function () use ($payment, $order) {
            $payment->update(['status' => 'pending']);
            $this->orders->restoreStock($order);

            if ($order->stock_deducted_at === null) {
                $order->forceFill(['status' => 'pending'])->save();
            }
        });

        return $order->fresh()->load('payment');
    }

    public function cancelAndRefund(int $id): Order
    {
        $order = $this->orders->findWithPaymentAndItems($id);

        if (in_array($order->status, ['cancelled', 'backorder_cancelled'], true)) {
            throw new OrderOperationException('Order is already cancelled.');
        }

        $payment = $order->payment;
        if (! $payment || $payment->status !== 'paid') {
            throw new OrderOperationException(
                'This order has no completed payment. Use the regular cancel instead.',
            );
        }

        DB::transaction(function () use ($order, $payment) {
            $payment->update(['status' => 'refunded']);
            $this->orders->restoreStock($order);
            $order->forceFill(['status' => 'cancelled'])->save();
        });

        $this->sendCancellationMail($order);

        return $order->fresh()->load('payment');
    }

    public function delete(int $id): void
    {
        $order = Order::findOrFail($id);

        if (! in_array($order->status, ['cancelled', 'pending'], true)) {
            throw new OrderOperationException('Only cancelled or pending orders can be deleted');
        }
        if ($order->stock_deducted_at) {
            throw new OrderOperationException('Cannot delete an order with deducted stock');
        }

        $order->delete();
    }

    private function sendCancellationMail(Order $order): void
    {
        if ($order->customer_email) {
            Mail::to($order->customer_email)->send(new OrderCancellationMail($order));
        }
    }
}
