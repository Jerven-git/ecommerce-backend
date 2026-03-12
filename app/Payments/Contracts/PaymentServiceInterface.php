<?php

namespace App\Payments\Contracts;

use App\Models\Order;
use App\Models\Payment;

interface PaymentServiceInterface
{
    public function createPending(Order $order, string $provider, array $init): Payment;

    public function markPaid(Payment $payment): void;
}
