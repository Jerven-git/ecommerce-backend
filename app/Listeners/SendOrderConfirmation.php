<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmation
{
    public function handle(PaymentConfirmed $event): void
    {
        $order = Order::find($event->payment->order_id);

        if (!$order || !$order->customer_email) {
            return;
        }

        Mail::to($order->customer_email)->send(new OrderConfirmationMail($order));
    }
}
