<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Modules\Backorders\Application\BackorderLifecycle;

class FulfillBackorder
{
    public function __construct(private readonly BackorderLifecycle $backorders) {}

    public function handle(PaymentConfirmed $event): void
    {
        $backorderId = ($event->payment->meta ?? [])['backorder_id'] ?? null;

        if (! $backorderId) {
            return;
        }

        $this->backorders->fulfillPaid($backorderId);
    }
}
