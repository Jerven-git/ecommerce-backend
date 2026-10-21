<?php

namespace App\Modules\Backorders\Application;

use App\Mail\BackorderConfirmationMail;
use App\Mail\BackorderConfirmedAdminMail;
use App\Models\Backorder;
use App\Models\SiteConfig;
use App\Payments\GatewayManager;
use App\Payments\PaymentAccess;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class BackorderCheckout
{
    public function __construct(
        private readonly BackorderQuery $backorders,
        private readonly BackorderPricing $pricing,
        private readonly GatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly PaymentAccess $access,
    ) {}

    /** @return array<string, mixed> */
    public function details(string $token): array
    {
        $backorder = $this->payableBackorder($token);
        $product = $backorder->product;
        $price = $this->pricing->calculate($backorder);

        return [
            'id' => $backorder->id,
            'product_name' => $product->name,
            'product_price' => $product->price,
            'quantity' => $backorder->quantity,
            ...$price,
            'customer_name' => $backorder->order->customer_name,
            'customer_email' => $backorder->order->customer_email,
            'order_id' => $backorder->order_id,
            'expires_at' => $backorder->token_expires_at->toIso8601String(),
            'stock_available' => $backorder->stock_reserved || $product->stock >= $backorder->quantity,
        ];
    }

    public function paymentBackorder(string $token): Backorder
    {
        $backorder = $this->payableBackorder($token);
        $product = $backorder->product;
        if (! $backorder->stock_reserved && (! $product || $product->stock < $backorder->quantity)) {
            $available = $product->stock ?? 0;
            throw new BackorderOperationException(
                "Sorry, this item is no longer available in the required quantity. Available stock: {$available}. Please contact us for assistance.",
                409,
            );
        }

        return $backorder;
    }

    /** @return array<string, mixed> */
    public function startPayment(Backorder $backorder, string $provider, Request $request): array
    {
        $price = $this->pricing->calculate($backorder);
        $init = $this->gateways->get($provider)->createPayment($backorder->order, [
            'amount_override' => $price['total'],
            'backorder_id' => $backorder->id,
        ]);
        $payment = $this->payments->createPending($backorder->order, $provider, array_merge($init, [
            'backorder_id' => $backorder->id,
        ]));
        $this->access->grant($request, $payment);

        $response = [
            'order_id' => $backorder->order_id,
            'amount' => $price['total'],
            'backorder_id' => $backorder->id,
            'payment_id' => $payment->id,
            'payment_token' => $payment->public_token,
        ];

        if ($provider === 'stripe') {
            $response['client_secret'] = $init['client_secret'];
        } else {
            $response['redirect_url'] = $init['redirect_url']
                ?? $init['approval_url']
                ?? $init['checkout_url']
                ?? $init['url']
                ?? null;
        }

        return $response;
    }

    public function confirmWithoutPayment(string $token): void
    {
        $backorder = $this->validTokenBackorder($token);
        if (in_array($backorder->status, ['paid', 'confirmed'], true)) {
            throw new BackorderOperationException('This backorder has already been processed', 422);
        }

        $backorder->update([
            'status' => 'confirmed',
            'payment_token' => null,
            'token_expires_at' => null,
        ]);

        Mail::to($backorder->order->customer_email)->send(new BackorderConfirmationMail($backorder));

        // Direct dependency retained for the existing tenant-owned operational setting.
        if ($adminEmail = SiteConfig::first()?->admin_email) {
            Mail::to($adminEmail)->send(new BackorderConfirmedAdminMail($backorder));
        }
    }

    private function payableBackorder(string $token): Backorder
    {
        $backorder = $this->validTokenBackorder($token);
        if ($backorder->status === 'paid') {
            throw new BackorderOperationException('This backorder has already been paid', 422);
        }

        return $backorder;
    }

    private function validTokenBackorder(string $token): Backorder
    {
        $backorder = $this->backorders->findByPublicToken($token);
        if (! $backorder) {
            throw new BackorderOperationException('Invalid payment link', 404);
        }

        if (! $backorder->isTokenValid()) {
            if ($backorder->status === 'notified') {
                $backorder->update(['status' => 'expired']);
            }

            throw new BackorderOperationException('This payment link has expired', 410);
        }

        return $backorder;
    }
}
