<?php

namespace App\Modules\Backorders\Application;

use App\Mail\BackorderCancellationMail;
use App\Mail\BackorderPaymentLinkMail;
use App\Models\Backorder;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class BackorderLifecycle
{
    public function __construct(
        private readonly BackorderQuery $backorders,
        private readonly BackorderSettings $settings,
    ) {}

    public function notify(int|string $id): Backorder
    {
        $backorder = $this->backorders->find($id, ['order', 'product']);
        if (! in_array($backorder->status, ['awaiting_stock', 'expired'], true)) {
            throw new BackorderOperationException(
                "Cannot notify a backorder with status '{$backorder->status}'",
                422,
            );
        }

        return $this->sendPaymentLink($backorder);
    }

    public function resend(int|string $id): Backorder
    {
        $backorder = $this->backorders->find($id, ['order', 'product']);
        if (! in_array($backorder->status, ['notified', 'expired'], true)) {
            throw new BackorderOperationException(
                "Cannot resend for a backorder with status '{$backorder->status}'",
                422,
            );
        }

        return $this->sendPaymentLink($backorder);
    }

    public function cancel(int|string $id): Backorder
    {
        $backorder = $this->backorders->find($id, ['order', 'product']);
        if (in_array($backorder->status, ['paid', 'cancelled'], true)) {
            throw new BackorderOperationException(
                "Cannot cancel a backorder with status '{$backorder->status}'",
                422,
            );
        }

        DB::transaction(function () use ($backorder) {
            if ($backorder->stock_reserved) {
                $product = Product::query()->whereKey($backorder->product_id)->lockForUpdate()->first();
                $product?->increment('stock', $backorder->quantity);
            }

            $backorder->update([
                'status' => 'cancelled',
                'payment_token' => null,
                'token_expires_at' => null,
                'stock_reserved' => false,
            ]);
        });

        $order = $backorder->order;
        $activeBackorders = $order->backorders()->whereNotIn('status', ['cancelled', 'paid'])->count();
        if ($activeBackorders === 0 && $order->backorders()->where('status', 'paid')->count() === 0) {
            $order->forceFill(['status' => 'backorder_cancelled'])->save();

            if ($order->payment?->status === 'pending') {
                $order->payment->update(['status' => 'failed']);
            }
        }

        Mail::to($order->customer_email)->send(new BackorderCancellationMail($backorder));

        return $backorder->fresh()->load(['order', 'product']);
    }

    public function markAsPaid(int|string $id): Backorder
    {
        $backorder = $this->backorders->find($id, ['order', 'product']);
        if ($backorder->status !== 'confirmed') {
            throw new BackorderOperationException(
                "Can only mark 'confirmed' backorders as paid. Current status: '{$backorder->status}'",
                422,
            );
        }

        DB::transaction(function () use ($backorder) {
            $backorder->update([
                'status' => 'paid',
                'paid_at' => now(),
                'stock_reserved' => false,
            ]);
            $this->advanceOrderWhenSettled($backorder);
        });

        return $backorder->fresh()->load(['order', 'product']);
    }

    public function fulfillPaid(int|string $id): void
    {
        DB::transaction(function () use ($id) {
            $backorder = $this->backorders->tenantQuery()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $backorder || $backorder->status === 'paid') {
                return;
            }

            $wasReserved = $backorder->stock_reserved;
            $backorder->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_token' => null,
                'token_expires_at' => null,
                'stock_reserved' => false,
            ]);

            if (! $wasReserved) {
                $product = Product::query()->whereKey($backorder->product_id)->lockForUpdate()->first();
                if (! $product || $product->stock < $backorder->quantity) {
                    Log::warning(
                        "Backorder #{$backorder->id} paid but insufficient stock to deduct. Product #{$backorder->product_id}, available: ".($product->stock ?? 0).", required: {$backorder->quantity}",
                    );
                }

                $product?->decrement('stock', $backorder->quantity);
            }

            $this->advanceOrderWhenSettled($backorder);
        });
    }

    public function expireTokens(): int
    {
        $expired = Backorder::query()
            ->where('status', 'notified')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now())
            ->get();

        foreach ($expired as $backorder) {
            DB::transaction(function () use ($backorder) {
                if ($backorder->stock_reserved) {
                    $product = Product::query()->whereKey($backorder->product_id)->lockForUpdate()->first();
                    $product?->increment('stock', $backorder->quantity);
                }

                $backorder->update([
                    'status' => 'expired',
                    'payment_token' => null,
                    'stock_reserved' => false,
                ]);
            });
        }

        return $expired->count();
    }

    private function sendPaymentLink(Backorder $backorder): Backorder
    {
        $expiryHours = $this->settings->paymentLinkExpiryHours();
        $token = Str::random(64);

        DB::transaction(function () use ($backorder, $token, $expiryHours) {
            $product = Product::query()->whereKey($backorder->product_id)->lockForUpdate()->first();
            if (! $product || $product->stock < $backorder->quantity) {
                throw new BackorderOperationException(
                    'Insufficient stock to send payment link. Available: '.($product->stock ?? 0).", required: {$backorder->quantity}.",
                    422,
                );
            }

            if (! $backorder->stock_reserved) {
                $product->decrement('stock', $backorder->quantity);
            }

            $backorder->update([
                'status' => 'notified',
                'payment_token' => $token,
                'token_expires_at' => now()->addHours($expiryHours),
                'notified_at' => now(),
                'stock_reserved' => true,
            ]);
        });

        $backorder->refresh()->load(['order', 'product']);
        Mail::to($backorder->order->customer_email)->send(
            new BackorderPaymentLinkMail($backorder, $token, $expiryHours),
        );

        return $backorder;
    }

    private function advanceOrderWhenSettled(Backorder $backorder): void
    {
        $order = $backorder->order;
        if ($order && $order->backorders()->whereNotIn('status', ['paid', 'cancelled'])->count() === 0) {
            $order->forceFill(['status' => 'processing'])->save();
        }
    }
}
