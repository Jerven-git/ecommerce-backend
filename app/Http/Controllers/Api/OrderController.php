<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\TaxSetting;
use App\Services\ShippingCalculator;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::query();

        // Include order items if requested
        if ($request->has('include') && $request->include === 'items') {
            $query->with('items');
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        $orders = $query->get();

        return response()->json(['data' => $orders]);
    }

    public function show($id)
    {
        $order = Order::with('items')->findOrFail($id);
        return response()->json(['data' => $order]);
    }

    public function store(Request $request, ShippingCalculator $shipping)
    {
        $validated = $this->validateOrderRequest($request);
        $this->ensureDeliveryFields($validated);

        DB::beginTransaction();

        try {
            [$rawSubtotal, $totalWeight, $orderItems, $taxItems] =
                $this->buildCartAndReserveStock($validated['items']);

            $tax = $this->calculateTax($rawSubtotal, $taxItems);

            $shippingCalc = $this->calculateShipping(
                $validated,
                $shipping,
                $totalWeight,
                $rawSubtotal
            );

            $finalTotal = $this->calculateFinalTotal($tax, $shippingCalc);

            $order = $this->createOrder($validated, $finalTotal);
            $this->createOrderItems($order, $orderItems);

            DB::commit();

            return response()->json([
                'message' => 'Order created successfully',
                'data' => [
                    'order' => $order->load('items'),
                    'breakdown' => [
                        'raw_subtotal' => round($rawSubtotal, 2),
                        'tax' => $tax,
                        'shipping' => $shippingCalc,
                        'final_total' => round($finalTotal, 2),
                    ],
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create order',
                'error' => $e->getMessage(),
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
            'shipping_address' => 'required|string',

            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',

            'shipping_options' => 'nullable|array',
            'shipping_options.*' => 'string',

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
     *   2: array<int, array<string, mixed>>, // orderItems
     *   3: array<int, array{price: float, quantity: int}> // taxItems
     * }
     */
    private function buildCartAndReserveStock(array $items): array
    {
        $rawSubtotal = 0.0;
        $totalWeight = 0.0;
        $orderItems = [];
        $taxItems = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $qty = (int) $item['quantity'];

            $product = Product::findOrFail($productId);

            if ($product->stock < $qty) {
                throw new \Exception("Insufficient stock for product: {$product->name}");
            }

            $price = (float) $product->price;
            $lineSubtotal = $price * $qty;

            $rawSubtotal += $lineSubtotal;

            $weight = (float) ($product->weight ?? 0);
            $totalWeight += $weight * $qty;

            $orderItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_price' => $product->price,
                'quantity' => $qty,
                'subtotal' => round($lineSubtotal, 2),
            ];

            $taxItems[] = [
                'price' => $price,
                'quantity' => $qty,
            ];
        }

        return [$rawSubtotal, $totalWeight, $orderItems, $taxItems];
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
            // stays consistent with your current comment
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

    private function createOrder(array $validated, float $finalTotal): Order
    {
        return Order::create([
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? '',
            'shipping_address' => $validated['shipping_address'],
            'total_amount' => round($finalTotal, 2),
            'status' => 'pending',
        ]);
    }

    private function createOrderItems(Order $order, array $orderItems): void
    {
        foreach ($orderItems as $item) {
            $order->items()->create($item);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled'
        ]);

        $order = Order::findOrFail($id);
        $order->status = $validated['status'];
        $order->save();

        return response()->json([
            'message' => 'Order status updated successfully',
            'data' => $order
        ]);
    }

    public function destroy($id)
    {
        $order = Order::findOrFail($id);
        $order->delete();

        return response()->json([
            'message' => 'Order deleted successfully'
        ]);
    }
}