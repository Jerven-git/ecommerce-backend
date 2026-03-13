<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Payments\Contracts\ChecksPaymentStatus;

class PaymentController extends Controller
{
    public function pay(Order $order, Request $request, GatewayManager $manager, PaymentService $payments)
    {
        $existingPayment = $order->payment;
        abort_if($existingPayment && $existingPayment->status === 'paid', 422, 'Order is already paid');
        abort_if($existingPayment && $existingPayment->status === 'pending', 422, 'A payment is already in progress for this order');

        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['paypal', 'square'])],
        ]);

        $provider = $data['provider'];
        $gateway = $manager->get($provider);

        $init = $gateway->createPayment($order, [
            'user_id' => (string) optional($request->user())->id,
        ]);

        $payment = $payments->createPending($order, $provider, $init);

        return response()->json([
            'payment_id'    => $payment->id,
            'provider'      => $provider,
            'provider_ref'  => $payment->provider_ref,
            'redirect_url'  => $init['redirect_url'] ?? $init['approval_url'] ?? null,
        ]);
    }

    public function stripeIntent(Order $order, GatewayManager $manager, PaymentService $payments)
    {
        $existingPayment = $order->payment;
        abort_if($existingPayment && $existingPayment->status === 'paid', 422, 'Order is already paid');
        abort_if($existingPayment && $existingPayment->status === 'pending', 422, 'A payment is already in progress for this order');

        $gateway = $manager->get('stripe');
        $init = $gateway->createPayment($order);

        $payment = $payments->createPending($order, 'stripe', $init);

        return response()->json([
            'payment_id'    => $payment->id,
            'client_secret' => $init['client_secret'],
        ]);
    }

    public function show(Request $request, Payment $payment, GatewayManager $manager, PaymentService $payments)
    {
        $order = $payment->order;

        if ($order && $order->user_id) {
            if (!$request->user() || (int) $order->user_id !== (int) $request->user()->id) {
                abort(403);
            }
        }

        if ($payment->status === 'pending' && $payment->created_at?->lt(now()->subSeconds(3))) {
            /** @var PaymentGateway $gateway */
            $gateway = $manager->get($payment->provider);
            if ($gateway instanceof ChecksPaymentStatus) {
                $result = $gateway->verifyPayment($payment);

                if (!empty($result['meta']) && is_array($result['meta'])) {
                    $payment->update([
                        'meta' => array_merge($payment->meta ?? [], $result['meta']),
                    ]);
                    $payment->refresh();
                }

                if (!empty($result['paid'])) {
                    $payments->markPaid($payment);
                    $payment->refresh();
                } elseif (!empty($result['failed'])) {
                    $payment->update(['status' => 'failed']);
                    $payment->refresh();
                }
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
