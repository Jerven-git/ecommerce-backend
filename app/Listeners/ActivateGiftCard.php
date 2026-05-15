<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Mail\GiftCardMail;
use App\Models\GiftCard;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;

class ActivateGiftCard
{
    public function handle(PaymentConfirmed $event): void
    {
        $order = Order::find($event->payment->order_id);

        if (! $order || $order->delivery_method !== 'gift_card') {
            return;
        }

        $card = GiftCard::where('order_id', $order->id)
            ->where('status', GiftCard::STATUS_PENDING)
            ->first();

        if (! $card) {
            return;
        }

        $card->update([
            'status' => GiftCard::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        Mail::to($card->recipient_email)->send(new GiftCardMail($card));
    }
}
