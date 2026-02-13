<?php

namespace App\Payments\Contracts;

use App\Models\Order;

interface PaymentGateway
{
    public function key(): string;

    /**
     * Create a payment "start" response for frontend.
     * Return an array that your frontend understands (e.g. client_secret, approval_url, etc).
     */
    public function createPayment(Order $order, array $meta = []): array;
}
