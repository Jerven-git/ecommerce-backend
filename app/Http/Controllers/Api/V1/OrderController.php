<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Backorder;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteConfig;
use App\Repositories\OrderRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Discount;
use App\Models\TaxSetting;
use App\Services\ShippingCalculator;
use App\Payments\PaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderCancellationMail;

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
        $validated = $this->validateOrderRequest($request);
        $this->ensureDeliveryFields($validated);

        DB::beginTransaction();

        try {
            [$rawSubtotal, $totalWeight, $totalVolumeCbm, $orderItems, $taxItems, $backorderItems] =
                $this->buildCartAndReserveStock($validated['items']);

            $tax = $this->calculateTax($rawSubtotal, $taxItems);

            $shippingCalc = $this->calculateShipping(
                $validated,
                $shipping,
                $totalWeight,
                $totalVolumeCbm,
                $rawSubtotal
            );

            $totalBeforeDiscount = $this->calculateFinalTotal($tax, $shippingCalc);

            $discountAmount = 0.0;
            $discountCode = $request->input('discount_code');
            $discount = null;

            if ($discountCode) {
                $discount = Discount::where('code', $discountCode)->lockForUpdate()->first();
                if ($discount && $discount->isValid($rawSubtotal)) {
                    $discountAmount = $discount->calculateDiscount($rawSubtotal);
                }
            }

            $finalTotal = max(0, $totalBeforeDiscount - $discountAmount);

            $taxAmount = (float) ($tax['tax_amount'] ?? 0);
            $shippingTotal = (float) ($shippingCalc['total'] ?? 0);

            $hasBackorders = !empty($backorderItems);
            $order = $this->createOrder($validated, $finalTotal, $rawSubtotal, $taxAmount, $shippingTotal, $discountCode, $discountAmount, $hasBackorders);
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

    private function validateOrderRequest(Request $request): array
    {
        return $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email',
            'customer_phone' => 'nullable|string',

            'delivery_method' => 'required|in:delivery,pickup',
            'shipping_address' => 'required_if:delivery_method,delivery|nullable|string',

            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',

            'shipping_options' => 'nullable|array',
            'shipping_options.*' => 'string',

            'payment_method' => 'nullable|string|in:cash,stripe,paypal,square',

            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
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

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $qty = (int) $item['quantity'];

            $product = $products->get($productId);

            if (!$product) {
                throw new \Exception("Product not found: {$productId}");
            }

            $inStockQty = min($qty, $product->stock);
            $backorderQty = $qty - $inStockQty;

            // If not enough stock and backorder not allowed, throw
            if ($backorderQty > 0 && !$product->canBackorder()) {
                throw new \Exception("Insufficient stock for product: {$product->name}");
            }

            $price = (float) $product->price;

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
                    'product_name' => $product->name,
                    'product_price' => $product->price,
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
                // Still add to order items so the full order is recorded
                $boLineSubtotal = $price * $backorderQty;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name . ' (Backorder)',
                    'product_price' => $product->price,
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
                    'quantity' => $backorderQty,
                    'charge_policy' => $product->backorder_charge_policy,
                ];
            }
        }

        return [$rawSubtotal, $totalWeight, $totalVolumeCbm, $orderItems, $taxItems, $backorderItems];
    }

    private function calculateTax(float $rawSubtotal, array $taxItems): array
    {
        $taxSetting = TaxSetting::first();

        if (!$taxSetting) {
            return [
                'subtotal' => round($rawSubtotal, 2),
                'tax_amount' => 0,
                'total' => round($rawSubtotal, 2),
                'tax_display_mode' => 'exclusive',
                'tax_rate' => 0,
                'tax_name' => 'Tax',
            ];
        }

        return $taxSetting->calculateCartTax($taxItems);
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

        $shippingOptions = $validated['shipping_options'] ?? [];

        $shippingCalc = $shipping->calculateShipping([
            'country' => $validated['country'],
            'state' => $validated['state'],
            'city' => $validated['city'],
            'weight' => $totalWeight,
            'volume_cbm' => $totalVolumeCbm,
            'order_amount' => $rawSubtotal,
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

    private function calculateFinalTotal(array $tax, array $shippingCalc): float
    {
        $taxTotal = (float) ($tax['total'] ?? 0);
        $shippingTotal = (float) ($shippingCalc['total'] ?? 0);

        return $taxTotal + $shippingTotal;
    }

    private function createOrder(
        array $validated,
        float $finalTotal,
        float $subtotal,
        float $taxAmount,
        float $shippingAmount,
        ?string $discountCode = null,
        float $discountAmount = 0,
        bool $hasBackorders = false
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
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'shipping_amount' => round($shippingAmount, 2),
            'discount_code' => $discountCode,
            'discount_amount' => round($discountAmount, 2),
            'status' => $hasBackorders ? 'backorder_awaiting_stock' : 'pending',
            'has_backorder_items' => $hasBackorders,
        ]);
    }

    private function createOrderItems(Order $order, array $orderItems): void
    {
        $order->items()->createMany($orderItems);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,backorder_awaiting_stock,backorder_notified,backorder_expired,backorder_cancelled'
        ]);

        $order = Order::findOrFail($id);

        $allowedTransitions = [
            'pending' => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered'],
            'delivered' => ['processing'],
            'cancelled' => ['pending'],
            'backorder_awaiting_stock' => [],
            'backorder_notified' => [],
            'backorder_expired' => [],
            'backorder_cancelled' => [],
        ];

        $allowed = $allowedTransitions[$order->status] ?? [];

        if (!in_array($validated['status'], $allowed, true)) {
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
            'data' => $order->load('payment')
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

        if (!$payment || $payment->status !== 'paid') {
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
            'message' => 'Order deleted successfully'
        ]);
    }
}