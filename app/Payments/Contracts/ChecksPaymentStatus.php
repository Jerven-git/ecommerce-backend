<?php

namespace App\Payments\Contracts;

use App\Models\Payment;

interface ChecksPaymentStatus
{
    public function verifyPayment(Payment $payment): array;
}
