<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\OrderCancellationMail;
use App\Models\Backorder;
use App\Models\Discount;
use App\Models\GiftCard;
use App\Models\GiftCardDenomination;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteConfig;
use App\Models\TaxSetting;
use App\Payments\PaymentService;
use App\Repositories\OrderRepository;
use App\Services\ShippingCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function __construct(private OrderRepository $orders) {}

    public function index(Request $request)
    {
        $query = Order::query();

        $includes = array_filter(explode(',', $request->query('include', '')));
        $allowedIncludes = ['items', 'payment', 'shipment'];
        $query->with(array_intersect($includes, $allowedIncludes));

        $query->when($request->filled('status'), function ($q) use ($request) {
            $status = $request->query('status');
            if ($status === 'cancelled') {
                $q->whereIn('status', ['cancelled', 'backorder_cancelled']);
            } elseif ($status === 'backorder') {
                $q->where('status', 'like', 'backorder_%');
            } else {
                $q->where('status', $status);
            }
        })
            ->search($request->query('search'));

        $allowedSorts = ['created_at', 'id', 'status', 'total'];
        $sort = in_array($request->query('sort'), $allowedSorts, true) ? $request->query('sort') : 'created_at';

        $order = strtolower($request->query('order', 'desc'));
        $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';

        $query->orderBy($sort, $order);

        $statusCounts = Cache::remember('order_status_counts', 300, fn () => $this->orders->statusCounts());

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginated = $query->paginate($perPage);

        return response()->json(array_merge($paginated->toArray(), [
            'status_counts' => $statusCounts,
        ]));
    }

    public function show($id)
    {
        $order = $this->orders->findWithAll($id);

        return response()->json(['data' => $order]);
    }

    public function store(Request $request, ShippingCalculator $shipping)
    {
        if ($request->input('delivery_method') === 'gift_card') {
            return $this->storeGiftCardOrder($request);
        }

        $validated = $this->validateOrderRequest($request);
        $this->ensureDeliveryFields($validated);

        DB::beginTransaction();

        try {
            [$rawSubtotal, $totalWeight, $totalVolumeCbm, $orderItems, $taxItems, $backorderItems] =
                $this->buildCartAndReserveStock($validated['items']);

            $shippingCalc = $this->calculateShipping(
                $validated,
                $shipping,
                $totalWeight,
                $totalVolumeCbm,
                $rawSubtotal
            );

            $shippingTotal = (float) ($shippingCalc['total'] ?? 0);

            // Calculate discount on ex-tax subtotal (before tax)
            $discountAmount = 0.0;
            $discountCode = $request->input('discount_code');
            $discount = null;

            $buyerCountry = $validated['country'] ?? null;
            $buyerState = $validated['state'] ?? null;

            // Resolve regional tax to determine ex-tax subtotal for discount
            $taxSetting = TaxSetting::first();
            $resolved = $taxSetting ? $taxSetting->resolveForRegion($buyerCountry, $buyerState) : null;
            $exTaxSubtotal = $rawSubtotal;
            if ($resolved && $resolved['rate'] > 0 && $resolved['mode'] === 'inclusive') {
                $exTaxSubtotal = $rawSubtotal / (1 + $resolved['rate'] / 100);
            }

            if ($discountCode) {
                $discount = Discount::where('code', $discountCode)->lockForUpdate()->first();
                if ($discount && $discount->isValid($exTaxSubtotal)) {
                    $discountAmount = $discount->calculateDiscount($exTaxSubtotal);
                }
            }

            // Calculate tax on (discounted subtotal + shipping) with regional rate
            $tax = $this->calculateOrderTax($taxItems, $discountAmount, $shippingTotal, $buyerCountry, $buyerState);

            $finalTotal = (float) ($tax['total'] ?? 0);
            $taxAmount = (float) ($tax['tax_amount'] ?? 0);

            $hasBackorders = ! empty($backorderItems);
            $exTaxSubtotal = (float) ($tax['ex_tax_subtotal'] ?? $rawSubtotal);

            $currencyCode = $this->resolveShopCurrency();

            $order = $this->createOrder(
                $validated,
                $finalTotal,
                $exTaxSubtotal,
                $taxAmount,
                $shippingTotal,
                $discountCode,
                $discountAmount,
                $hasBackorders,
                $tax['rule_id'] ?? null,
                $tax['region_label'] ?? null,
                $currencyCode,
            );
            $this->createOrderItems($order, $orderItems);

            // Create backorder records for out-of-stock items
            if ($hasBackorders) {
                foreach ($backorderItems as $bi) {
                    Backorder::create([
                        'order_id' => $order->id,
                        'product_id' => $bi['product_id'],
                        'quantity' => $bi['quantity'],
                        'status' => 'awaiting_stock',
                        'charge_policy' => $bi['charge_policy'],
                    ]);
                }
            }

            if ($discount && $discountAmount > 0) {
                $discount->increment('used_count');
            }

            // Apply gift card redemption
            $giftCardCode = $request->input('gift_card_code');
            if ($giftCardCode) {
                $giftCard = GiftCard::where('code', strtoupper(trim($giftCardCode)))->lockForUpdate()->first();
                if ($giftCard && $giftCard->isUsable()) {
                    $applied = min((float) $giftCard->balance, $finalTotal);
                    $giftCard->deduct($applied);
                    $order->gift_card_code = $giftCard->code;
                    $order->gift_card_amount = $applied;
                    $order->total_amount = max(0, round($finalTotal - $applied, 2));
                    $order->save();
                    $finalTotal = $order->total_amount;
                }
            }

            if (($validated['payment_method'] ?? null) === 'cash') {
                app(PaymentService::class)->createPending($order, 'cash', []);
            }

            DB::commit();

            return response()->json([
                'message' => 'Order created successfully',
                'data' => [
                    'order' => $order->load('items'),
                    'breakdown' => [
                        'raw_subtotal' => round($rawSubtotal, 2),
                        'tax' => $tax,
                        'shipping' => $shippingCalc,
                        'discount_code' => $discountCode,
                        'discount_amount' => round($discountAmount, 2),
                        'final_total' => round($finalTotal, 2),
                    ],
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'message' => 'Failed to create order',
                'error' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'Something went wrong',
            ], 422);
        }
    }

    private function storeGiftCardOrder(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email',
            'customer_phone' => 'nullable|string',
            'payment_method' => 'required|string|in:stripe,paypal,square',
            'gift_card_denomination_id' => 'required|exists:gift_card_denominations,id',
            'gift_card_recipient_email' => 'required|email',
            'gift_card_recipient_name' => 'nullable|string|max:255',
            'gift_card_message' => 'nullable|string|max:1000',
        ]);

        $denomination = GiftCardDenomination::findOrFail($validated['gift_card_denomination_id']);

        if (! $denomination->is_enabled) {
            return response()->json(['message' => 'Selected denomination is not available.'], 422);
        }

        $currencyCode = $this->resolveShopCurrency();

        DB::beginTransaction();

        try {
            $order = Order::create([
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $validated['customer_phone'] ?? '',
                'shipping_address' => 'Gift Card',
                'delivery_method' => 'gift_card',
                'total_amount' => round((float) $denomination->amount, 2),
                'currency' => $currencyCode,
                'subtotal' => round((float) $denomination->amount, 2),
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
                'currency' => $currencyCode,
                'order_id' => $order->id,
                'purchaser_name' => $validated['customer_name'],
                'purchaser_email' => $validated['customer_email'],
                'recipient_name' => $validated['gift_card_recipient_name'] ?? null,
                'recipient_email' => $validated['gift_card_recipient_email'],
                'message' => $validated['gift_card_message'] ?? null,
                'status' => GiftCard::STATUS_PENDING,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Gift card order created successfully',
                'data' => ['order' => $order],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'message' => 'Failed to create gift card order',
                'error' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'Something went wrong',
            ], 422);
        }
    }

    private function validateOrderRequest(Request $request): array
    {
        return $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email',
            'customer_phone' => 'nullable|string',

            'delivery_method' => 'required|in:delivery,pickup',
            'shipping_address' => 'required_if:delivery_method,delivery|nullable|string',

            'country' => 'required_if:delivery_method,delivery|nullable|string',
            'state' => 'required_if:delivery_method,delivery|nullable|string',
            'city' => 'nullable|string',

            'shipping_method' => 'nullable|string|in:standard,express,registered',
            'shipping_options' => 'nullable|array',
            'shipping_options.*' => 'string|in:insurance',

            'payment_method' => 'nullable|string|in:cash,stripe,paypal,square',

            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.variant_id' => 'nullable|integer|exists:product_variants,id',

            'gift_card_code' => 'nullable|string|max:20',
        ]);
    }

    private function ensureDeliveryFields(array $validated): void
    {
        if (($validated['delivery_method'] ?? null) !== 'delivery') {
            return;
        }

        foreach (['country', 'state', 'city'] as $field) {
            if (empty($validated[$field])) {
                abort(response()->json([
                    'message' => "Missing {$field} for delivery shipping calculation",
                ], 422));
            }
        }
    }

    /**
     * @return array{
     *   0: float, // rawSubtotal
     *   1: float, // totalWeight
     *   2: float, // totalVolumeCbm
     *   3: array<int, array<string, mixed>>, // orderItems
     *   4: array<int, array{price: float, quantity: int}>, // taxItems
     *   5: array<int, array<string, mixed>> // backorderItems
     * }
     */
    private function buildCartAndReserveStock(array $items): array
    {
        $rawSubtotal = 0.0;
        $totalWeight = 0.0;
        $totalVolumeCbm = 0.0;
        $orderItems = [];
        $taxItems = [];
        $backorderItems = [];

        // Lock all products upfront to prevent concurrent overselling
        $productIds = array_column($items, 'product_id');
        $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

        // Lock variant rows in ID order to prevent deadlocks
        $variantIds = array_filter(array_column($items, 'variant_id'));
        $variants = collect();
        if (! empty($variantIds)) {
            $variants = ProductVariant::with('optionValues.option')
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
        }

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $variantId = ! empty($item['variant_id']) ? (int) $item['variant_id'] : null;
            $qty = (int) $item['quantity'];

            $product = $products->get($productId);

            if (! $product) {
                throw new \Exception("Product not found: {$productId}");
            }

            $variant = $variantId ? $variants->get($variantId) : null;

            if ($variantId && ! $variant) {
                throw new \Exception("Variant not found: {$variantId}");
            }

            $availableStock = $variant ? $variant->stock : $product->stock;
            $inStockQty = min($qty, $availableStock);
            $backorderQty = $qty - $inStockQty;

            // If not enough stock and backorder not allowed, throw
            if ($backorderQty > 0 && ! $product->canBackorder()) {
                throw new \Exception("Insufficient stock for product: {$product->name}");
            }

            $price = $variant
                ? (float) ($variant->price ?? $product->price)
                : (float) $product->price;

            $selectedOptions = null;
            if ($variant) {
                $selectedOptions = $variant->optionValues->map(fn ($val) => [
                    'option' => $val->option->name,
                    'value' => $val->label,
                ])->values()->toArray();
            }

            // Process in-stock portion as normal order items
            if ($inStockQty > 0) {
                $lineSubtotal = $price * $inStockQty;
                $rawSubtotal += $lineSubtotal;

                if ($product->shipping_calc_type === 'dimensions') {
                    $totalVolumeCbm += $product->volume_cbm * $inStockQty;
                } else {
                    $totalWeight += (float) ($product->weight ?? 0) * $inStockQty;
                }

                $orderItems[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'variant_sku' => $variant?->sku,
                    'selected_options' => $selectedOptions,
                    'product_name' => $product->name,
                    'product_price' => $price,
                    'quantity' => $inStockQty,
                    'subtotal' => round($lineSubtotal, 2),
                ];

                $taxItems[] = [
                    'price' => $price,
                    'quantity' => $inStockQty,
                ];
            }

            // Process backorder portion
            if ($backorderQty > 0) {
                $boLineSubtotal = $price * $backorderQty;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'variant_sku' => $variant?->sku,
                    'selected_options' => $selectedOptions,
                    'product_name' => $product->name.' (Backorder)',
                    'product_price' => $price,
                    'quantity' => $backorderQty,
                    'subtotal' => round($boLineSubtotal, 2),
                ];

                // Only include in subtotal/tax if charge policy is "charged_now"
                if ($product->backorder_charge_policy === 'charged_now') {
                    $rawSubtotal += $boLineSubtotal;
                    $taxItems[] = [
                        'price' => $price,
                        'quantity' => $backorderQty,
                    ];
                }

                $backorderItems[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'quantity' => $backorderQty,
                    'charge_policy' => $product->backorder_charge_policy,
                ];
            }
        }

        return [$rawSubtotal, $totalWeight, $totalVolumeCbm, $orderItems, $taxItems, $backorderItems];
    }

    private function calculateOrderTax(
        array $taxItems,
        float $discountAmount,
        float $shippingAmount,
        ?string $country = null,
        ?string $state = null
    ): array {
        $taxSetting = TaxSetting::first();

        if (! $taxSetting) {
            $rawSubtotal = 0;
            foreach ($taxItems as $item) {
                $rawSubtotal += $item['price'] * $item['quantity'];
            }
            $discountedSubtotal = max(0, $rawSubtotal - $discountAmount);
            $taxableAmount = $discountedSubtotal + $shippingAmount;

            return [
                'raw_subtotal' => round($rawSubtotal, 2),
                'ex_tax_subtotal' => round($rawSubtotal, 2),
                'discounted_subtotal' => round($discountedSubtotal, 2),
                'shipping' => round($shippingAmount, 2),
                'taxable_amount' => round($taxableAmount, 2),
                'tax_amount' => 0,
                'total' => round($taxableAmount, 2),
                'tax_display_mode' => 'exclusive',
                'tax_rate' => 0,
                'tax_name' => 'Tax',
                'rule_id' => null,
                'region_label' => null,
            ];
        }

        // Resolve regional tax rate
        $resolved = $taxSetting->resolveForRegion($country, $state);

        $result = $taxSetting->calculateOrderTotals(
            $taxItems,
            $discountAmount,
            $shippingAmount,
            $resolved['rate'],
            $resolved['name'],
            $resolved['mode']
        );

        $result['rule_id'] = $resolved['rule_id'];
        $result['region_label'] = $resolved['region_label'];

        return $result;
    }

    private function calculateShipping(
        array $validated,
        ShippingCalculator $shipping,
        float $totalWeight,
        float $totalVolumeCbm,
        float $rawSubtotal
    ): array {
        $shippingCalc = $this->defaultShippingCalc();

        if (($validated['delivery_method'] ?? null) !== 'delivery') {
            return $shippingCalc;
        }

        $shippingMethod = $validated['shipping_method'] ?? 'standard';
        $shippingOptions = $validated['shipping_options'] ?? [];

        $shippingCalc = $shipping->calculateShipping([
            'country' => $validated['country'],
            'state' => $validated['state'],
            'city' => $validated['city'],
            'weight' => $totalWeight,
            'volume_cbm' => $totalVolumeCbm,
            'order_amount' => $rawSubtotal,
            'method' => $shippingMethod,
            'options' => $shippingOptions,
        ]);

        if (isset($shippingCalc['error'])) {
            abort(response()->json([
                'message' => $shippingCalc['error'],
            ], 422));
        }

        return $shippingCalc;
    }

    private function defaultShippingCalc(): array
    {
        return [
            'base_shipping' => 0,
            'weight_fee' => 0,
            'volume_fee' => 0,
            'options_fee' => 0,
            'total' => 0,
            'free_shipping' => false,
            'zone' => 'pickup',
        ];
    }

    private function createOrder(
        array $validated,
        float $finalTotal,
        float $subtotal,
        float $taxAmount,
        float $shippingAmount,
        ?string $discountCode = null,
        float $discountAmount = 0,
        bool $hasBackorders = false,
        ?int $taxRuleId = null,
        ?string $taxRegion = null,
        string $currency = 'USD',
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
            'currency' => $currency,
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

    /**
     * The shop runs in a single currency configured by the admin in SiteConfig.
     * Falls back to 'USD' if unset.
     */
    private function resolveShopCurrency(): string
    {
        return SiteConfig::queryForDefaultStore()->value('currency_code') ?: 'USD';
    }

    private function createOrderItems(Order $order, array $orderItems): void
    {
        $order->items()->createMany($orderItems);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,backorder_awaiting_stock,backorder_notified,backorder_expired,backorder_cancelled',
        ]);

        $order = Order::findOrFail($id);

        $allowedTransitions = [
            'pending' => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered'],
            'delivered' => ['processing'], // possible bug
            'cancelled' => ['pending'],
            'backorder_awaiting_stock' => [],
            'backorder_notified' => [],
            'backorder_expired' => [],
            'backorder_cancelled' => [],
        ];

        $allowed = $allowedTransitions[$order->status] ?? [];

        if (! in_array($validated['status'], $allowed, true)) {
            return response()->json([
                'message' => "Cannot transition from '{$order->status}' to '{$validated['status']}'",
            ], 422);
        }

        // Block cancellation if payment has been received
        if ($validated['status'] === 'cancelled' || $validated['status'] === 'backorder_cancelled') {
            $payment = $order->payment;
            if ($payment && $payment->status === 'paid') {
                return response()->json([
                    'message' => 'Cannot cancel an order with a completed payment. Please undo the payment first.',
                ], 422);
            }
        }

        $order->status = $validated['status'];
        $order->save();

        if ($validated['status'] === 'cancelled') {
            DB::transaction(function () use ($order) {
                $payment = $order->payment;
                if ($payment && $payment->status === 'pending') {
                    $payment->update(['status' => 'failed']);
                }

                $this->orders->restoreStock($order);
            });

            if ($order->customer_email) {
                Mail::to($order->customer_email)->send(new OrderCancellationMail($order));
            }
        }

        return response()->json([
            'message' => 'Order status updated successfully',
            'data' => $order->load('payment'),
        ]);
    }

    public function confirmPayment($id, PaymentService $payments)
    {
        $order = $this->orders->findWithPayment($id);
        $payment = $order->payment;

        abort_unless($payment, 404, 'No payment record found');
        abort_if($payment->status === 'paid', 422, 'Payment already confirmed');
        abort_unless($payment->provider === 'cash', 422, 'Only cash payments can be manually confirmed');

        $payments->markPaid($payment);

        return response()->json([
            'message' => 'Payment confirmed',
            'data' => $order->fresh()->load('payment'),
        ]);
    }

    public function undoPayment($id)
    {
        $order = $this->orders->findWithPayment($id);
        $payment = $order->payment;

        abort_unless($payment, 404, 'No payment record found');
        abort_unless($payment->provider === 'cash', 422, 'Only cash payments can be undone');
        abort_unless($payment->status === 'paid', 422, 'Payment is not confirmed');

        DB::transaction(function () use ($payment, $order) {
            $payment->update(['status' => 'pending']);

            $this->orders->restoreStock($order);

            if ($order->stock_deducted_at === null) {
                $order->forceFill(['status' => 'pending'])->save();
            }
        });

        return response()->json([
            'message' => 'Payment reverted to pending',
            'data' => $order->fresh()->load('payment'),
        ]);
    }

    public function cancelAndRefund(Request $request, $id)
    {
        $order = $this->orders->findWithPaymentAndItems($id);

        if (in_array($order->status, ['cancelled', 'backorder_cancelled'], true)) {
            return response()->json([
                'message' => 'Order is already cancelled.',
            ], 422);
        }

        $payment = $order->payment;

        if (! $payment || $payment->status !== 'paid') {
            return response()->json([
                'message' => 'This order has no completed payment. Use the regular cancel instead.',
            ], 422);
        }

        DB::transaction(function () use ($order, $payment) {
            $payment->update(['status' => 'refunded']);
            $this->orders->restoreStock($order);
            $order->forceFill(['status' => 'cancelled'])->save();
        });

        if ($order->customer_email) {
            Mail::to($order->customer_email)->send(new OrderCancellationMail($order));
        }

        return response()->json([
            'message' => 'Order cancelled. Please process the refund through your payment provider.',
            'data' => $order->fresh()->load('payment'),
        ]);
    }

    public function destroy($id)
    {
        $order = Order::findOrFail($id);

        abort_unless(
            in_array($order->status, ['cancelled', 'pending'], true),
            422,
            'Only cancelled or pending orders can be deleted'
        );

        abort_if($order->stock_deducted_at, 422, 'Cannot delete an order with deducted stock');

        $order->delete();

        return response()->json([
            'message' => 'Order deleted successfully',
        ]);
    }
}
