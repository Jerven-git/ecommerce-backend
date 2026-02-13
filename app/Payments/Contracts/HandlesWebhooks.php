<?php

namespace App\Payments\Contracts;

use Illuminate\Http\Request;

interface HandlesWebhooks
{
    /**
     * Parse + normalize a webhook event after signature verification.
     * Return: provider, event_id, event_type, order_id (if possible), provider_ref, status
     */
    public function parseWebhook(array $event): array;
}
