<?php

namespace App\Modules\Orders\Application;

use App\Models\Backorder;
use App\Models\GiftCard;
use App\Models\GiftCardDenomination;
use App\Models\Order;
use App\Models\SiteConfig;
use Illuminate\Support\Facades\DB;

class OrderCheckout
{
    public function __construct(
        private readonly OrderCartBuilder $cartBuilder,
        private readonly OrderPricing $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{order: Order, breakdown: array<string, mixed>}
     */
    public function create(array $validated, ?string $discountCode, ?string $giftCardCode): array
    {
        return DB::transaction(function () use ($validated, $discountCode, $giftCardCode) {
            $cart = $this->cartBuilder->build($validated['items']);
            $shipping = $this->pricing->shipping($validated, $cart);
            $shippingTotal = (float) ($shipping['total'] ?? 0);

            [$discount, $discountAmount] = $this->pricing->discount(
                $discountCode,
                $cart->rawSubtotal,
                $validated['country'] ?? null,
                $validated['state'] ?? null,
            );
            $tax = $this->pricing->tax(
                $cart,
                $discountAmount,
                $shippingTotal,
                $validated['country'] ?? null,
                $validated['state'] ?? null,
            );

            $finalTotal = (float) ($tax['total'] ?? 0);
            $order = $this->persistOrder(
                $validated,
                $finalTotal,
                (float) ($tax['ex_tax_subtotal'] ?? $cart->rawSubtotal),
                (float) ($tax['tax_amount'] ?? 0),
                $shippingTotal,
                $discountCode,
                $discountAmount,
                $cart->hasBackorders(),
                $tax['rule_id'] ?? null,
                $tax['region_label'] ?? null,
            );

            $order->items()->createMany($cart->orderItems);
            $this->createBackorders($order, $cart->backorderItems);

            if ($discount && $discountAmount > 0) {
                $discount->increment('used_count');
            }

            $finalTotal = $this->applyGiftCard($order, $giftCardCode, $finalTotal);

            return [
                'order' => $order->load('items'),
                'breakdown' => [
                    'raw_subtotal' => round($cart->rawSubtotal, 2),
                    'tax' => $tax,
                    'shipping' => $shipping,
                    'discount_code' => $discountCode,
                    'discount_amount' => round($discountAmount, 2),
                    'final_total' => round($finalTotal, 2),
                ],
            ];
        });
    }

    /** @param array<string, mixed> $validated */
    public function createGiftCard(array $validated): Order
    {
        $denomination = GiftCardDenomination::findOrFail($validated['gift_card_denomination_id']);

        if (! $denomination->is_enabled) {
            throw new OrderOperationException('Selected denomination is not available.');
        }

        return DB::transaction(function () use ($validated, $denomination) {
            $currency = $this->shopCurrency();
            $amount = round((float) $denomination->amount, 2);

            $order = Order::create([
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $validated['customer_phone'] ?? '',
                'shipping_address' => 'Gift Card',
                'delivery_method' => 'gift_card',
                'total_amount' => $amount,
                'currency' => $currency,
                'subtotal' => $amount,
                'tax_amount' => 0,
                'shipping_amount' => 0,
                'discount_amount' => 0,
                'gift_card_amount' => 0,
                'status' => 'pending',
            ]);

            GiftCard::create([
                'code' => GiftCard::generateCode(),
                'original_amount' => $denomination->amount,
                'balance' => $denomination->amount,
                'currency' => $currency,
                'order_id' => $order->id,
                'purchaser_name' => $validated['customer_name'],
                'purchaser_email' => $validated['customer_email'],
                'recipient_name' => $validated['gift_card_recipient_name'] ?? null,
                'recipient_email' => $validated['gift_card_recipient_email'],
                'message' => $validated['gift_card_message'] ?? null,
                'status' => GiftCard::STATUS_PENDING,
            ]);

            return $order;
        });
    }

    /** @param array<string, mixed> $validated */
    private function persistOrder(
        array $validated,
        float $finalTotal,
        float $subtotal,
        float $taxAmount,
        float $shippingAmount,
        ?string $discountCode,
        float $discountAmount,
        bool $hasBackorders,
        ?int $taxRuleId,
        ?string $taxRegion,
    ): Order {
        return Order::create([
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? '',
            'shipping_address' => $validated['shipping_address'] ?? '',
            'delivery_method' => $validated['delivery_method'] ?? 'delivery',
            'country' => $validated['country'] ?? null,
            'state' => $validated['state'] ?? null,
            'city' => $validated['city'] ?? null,
            'total_amount' => round($finalTotal, 2),
            'currency' => $this->shopCurrency(),
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'shipping_amount' => round($shippingAmount, 2),
            'discount_code' => $discountCode,
            'discount_amount' => round($discountAmount, 2),
            'tax_rule_id' => $taxRuleId,
            'tax_region' => $taxRegion,
            'status' => $hasBackorders ? 'backorder_awaiting_stock' : 'pending',
            'has_backorder_items' => $hasBackorders,
        ]);
    }

    /** @param array<int, array<string, mixed>> $backorders */
    private function createBackorders(Order $order, array $backorders): void
    {
        foreach ($backorders as $backorder) {
            Backorder::create([
                'order_id' => $order->id,
                'product_id' => $backorder['product_id'],
                'quantity' => $backorder['quantity'],
                'status' => 'awaiting_stock',
                'charge_policy' => $backorder['charge_policy'],
            ]);
        }
    }

    private function applyGiftCard(Order $order, ?string $code, float $finalTotal): float
    {
        if (! $code) {
            return $finalTotal;
        }

        $giftCard = GiftCard::where('code', strtoupper(trim($code)))->lockForUpdate()->first();
        if (! $giftCard || ! $giftCard->isUsable()) {
            return $finalTotal;
        }

        $applied = min((float) $giftCard->balance, $finalTotal);
        $giftCard->deduct($applied);
        $order->gift_card_code = $giftCard->code;
        $order->gift_card_amount = $applied;
        $order->total_amount = max(0, round($finalTotal - $applied, 2));
        $order->save();

        return (float) $order->total_amount;
    }

    private function shopCurrency(): string
    {
        return SiteConfig::value('currency_code') ?: 'USD';
    }
}
