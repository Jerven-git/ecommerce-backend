<?php

namespace App\Modules\Payments\Application;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Scopes\StoreScope;
use App\Payments\Contracts\ChecksPaymentStatus;
use App\Payments\GatewayManager;
use App\Payments\PaymentAccess;
use App\Payments\PaymentService;
use Illuminate\Http\Request;

class PaymentFlow
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly PaymentAccess $access,
    ) {}

    /** @return array{payment_id: int, payment_token: string|null, provider: string, provider_ref: string|null, redirect_url: mixed} */
    public function startRedirect(Order $order, Request $request, string $provider): array
    {
        $this->ensurePaymentCanStart($order);

        $init = $this->gateways->get($provider)->createPayment($order, [
            'user_id' => (string) optional($request->user())->id,
        ]);

        $payment = $this->payments->createPending($order, $provider, $init);
        $this->access->grant($request, $payment);

        return [
            'payment_id' => $payment->id,
            'payment_token' => $payment->public_token,
            'provider' => $provider,
            'provider_ref' => $payment->provider_ref,
            'redirect_url' => $init['redirect_url'] ?? $init['approval_url'] ?? null,
        ];
    }

    /** @return array{payment_id: int, payment_token: string|null, client_secret: mixed} */
    public function startStripe(Order $order, Request $request): array
    {
        $this->ensurePaymentCanStart($order);

        $init = $this->gateways->get('stripe')->createPayment($order);
        $payment = $this->payments->createPending($order, 'stripe', $init);
        $this->access->grant($request, $payment);

        return [
            'payment_id' => $payment->id,
            'payment_token' => $payment->public_token,
            'client_secret' => $init['client_secret'],
        ];
    }

    /** @return array{id: int, status: string, provider: string, order_id: int|null, order_status: string|null} */
    public function status(Request $request, Payment $payment): array
    {
        // Do not disclose whether a sequential payment ID exists.
        abort_unless($this->access->allows($request, $payment), 404);

        if ($payment->status === 'pending' && $payment->created_at?->lt(now()->subSeconds(3))) {
            $gateway = $this->gateways->get($payment->provider);

            if ($gateway instanceof ChecksPaymentStatus) {
                $this->applyVerification($payment, $gateway->verifyPayment($payment));
            }
        }

        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'provider' => $payment->provider,
            'order_id' => $payment->order_id,
            'order_status' => Order::withoutGlobalScope(StoreScope::class)
                ->whereKey($payment->order_id)
                ->value('status'),
        ];
    }

    private function ensurePaymentCanStart(Order $order): void
    {
        $existingPayment = $order->payment;

        abort_if($existingPayment && $existingPayment->status === 'paid', 422, 'Order is already paid');
        abort_if($existingPayment && $existingPayment->status === 'pending', 422, 'A payment is already in progress for this order');
    }

    private function applyVerification(Payment $payment, array $result): void
    {
        if (! empty($result['meta']) && is_array($result['meta'])) {
            $payment->update([
                'meta' => array_merge($payment->meta ?? [], $result['meta']),
            ]);
            $payment->refresh();
        }

        if (! empty($result['paid'])) {
            $this->payments->markPaid($payment);
            $payment->refresh();
        } elseif (! empty($result['failed'])) {
            $payment->update(['status' => 'failed']);
            $payment->refresh();
        }
    }
}
