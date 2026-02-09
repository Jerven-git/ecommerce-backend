<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function pay(Order $order, Request $request, GatewayManager $manager, PaymentService $payments)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['stripe','paypal','square'])],
        ]);

        $gateway = $manager->get($data['provider']);

        $init = $gateway->createPayment($order, [
            'user_id' => (string) optional($request->user())->id,
        ]);

        $payments->createPending($order, $data['provider'], $init);

        return response()->json($init);
    }

    public function stripeIntent(Order $order)
    {
        \Stripe\Stripe::setApiKey(config('payment.stripe.secret_key'));

        $amountCents = (int) round($order->total_amount * 100);
        $currency = strtolower($order->currency ?? 'usd');

        $intent = \Stripe\PaymentIntent::create([
            'amount' => $amountCents,
            'currency' => $currency,
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'stripe',
            'provider_ref' => $intent->id,
            'status' => 'pending',
            'amount' => $amountCents,
            'currency' => strtoupper($currency),
            'meta' => [
                'client_secret_last4' => substr((string) $intent->client_secret, -6), // optional
            ],
        ]);

        return response()->json([
            'payment_id' => $payment->id,
            'client_secret' => $intent->client_secret,
        ]);
    }

    public function show(Request $request, Payment $payment)
    {
        $order = $payment->order;

        // Only restrict when the order is tied to a user account
        if ($order && $order->user_id) {
            if (!$request->user() || $order->user_id !== $request->user()->id) {
                abort(403);
            }
        }

        return response()->json([
            'id' => $payment->id,
            'status' => $payment->status,
            'provider' => $payment->provider,
            'order_id' => $payment->order_id,
        ]);
    }
}
