<?php

namespace App\Payments\Contracts;

use App\Models\Order;

interface PaymentGateway
{
    public function key(): string;

    /**
     * Create a payment "start" response for frontend:
     * - Stripe: client_secret
     * - PayPal: approval_url
     * - Square: payment_link_url (or payment init info)
     */
    public function createPayment(Order $order, array $meta = []): array;

    /**
     * Parse + normalize a webhook event after signature verification.
     * Return: provider, event_id, event_type, order_id (if possible), provider_ref, status
     */
    public function parseWebhook(array $event): array;
}