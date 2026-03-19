<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\BackorderCancellationMail;
use App\Mail\BackorderPaymentLinkMail;
use App\Models\Backorder;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteConfig;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class BackorderController extends Controller
{
    public function index(Request $request)
    {
        $query = Backorder::with(['order', 'product']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->query('order_id'));
        }

        if ($request->filled('search')) {
            $term = $request->query('search');
            $query->whereHas('order', function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                  ->orWhere('customer_email', 'like', "%{$term}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        $statusCounts = Backorder::toBase()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginated = $query->paginate($perPage);

        return response()->json(array_merge($paginated->toArray(), [
            'status_counts' => $statusCounts,
        ]));
    }

    public function show($id)
    {
        $backorder = Backorder::with(['order.items', 'product'])->findOrFail($id);
        return response()->json(['data' => $backorder]);
    }

    public function notify(Request $request, $id)
    {
        $backorder = Backorder::with(['order', 'product'])->findOrFail($id);

        if (!in_array($backorder->status, ['awaiting_stock', 'expired'])) {
            return response()->json([
                'message' => "Cannot notify a backorder with status '{$backorder->status}'",
            ], 422);
        }

        // Check if product has enough stock to fulfill the backorder
        $product = $backorder->product;
        if (!$product || $product->stock < $backorder->quantity) {
            $available = $product->stock ?? 0;
            return response()->json([
                'message' => "Insufficient stock to send payment link. Available: {$available}, required: {$backorder->quantity}.",
            ], 422);
        }

        $config = SiteConfig::first();
        $expiryHours = $config?->backorder_payment_link_expiry_hours ?? 24;

        $token = Str::random(64);

        $backorder->update([
            'status' => 'notified',
            'payment_token' => $token,
            'token_expires_at' => now()->addHours($expiryHours),
            'notified_at' => now(),
        ]);

        Mail::to($backorder->order->customer_email)->send(
            new BackorderPaymentLinkMail($backorder, $token, $expiryHours)
        );

        return response()->json([
            'message' => 'Customer notified with payment link',
            'data' => $backorder->fresh()->load(['order', 'product']),
        ]);
    }

    public function resend($id)
    {
        $backorder = Backorder::with(['order', 'product'])->findOrFail($id);

        if (!in_array($backorder->status, ['notified', 'expired'])) {
            return response()->json([
                'message' => "Cannot resend for a backorder with status '{$backorder->status}'",
            ], 422);
        }

        // Check if product has enough stock to fulfill the backorder
        $product = $backorder->product;
        if (!$product || $product->stock < $backorder->quantity) {
            $available = $product->stock ?? 0;
            return response()->json([
                'message' => "Insufficient stock to resend payment link. Available: {$available}, required: {$backorder->quantity}.",
            ], 422);
        }

        $config = SiteConfig::first();
        $expiryHours = $config?->backorder_payment_link_expiry_hours ?? 24;

        $token = Str::random(64);

        $backorder->update([
            'status' => 'notified',
            'payment_token' => $token,
            'token_expires_at' => now()->addHours($expiryHours),
            'notified_at' => now(),
        ]);

        Mail::to($backorder->order->customer_email)->send(
            new BackorderPaymentLinkMail($backorder, $token, $expiryHours)
        );

        return response()->json([
            'message' => 'Payment link resent to customer',
            'data' => $backorder->fresh()->load(['order', 'product']),
        ]);
    }

    public function cancel($id)
    {
        $backorder = Backorder::with(['order', 'product'])->findOrFail($id);

        if (in_array($backorder->status, ['paid', 'cancelled'])) {
            return response()->json([
                'message' => "Cannot cancel a backorder with status '{$backorder->status}'",
            ], 422);
        }

        $backorder->update([
            'status' => 'cancelled',
            'payment_token' => null,
            'token_expires_at' => null,
        ]);

        // Update parent order status if all backorders are cancelled
        $order = $backorder->order;
        $activeBackorders = $order->backorders()->whereNotIn('status', ['cancelled', 'paid'])->count();

        if ($activeBackorders === 0) {
            $paidBackorders = $order->backorders()->where('status', 'paid')->count();
            if ($paidBackorders === 0) {
                $order->forceFill(['status' => 'backorder_cancelled'])->save();

                $payment = $order->payment;
                if ($payment && $payment->status === 'pending') {
                    $payment->update(['status' => 'failed']);
                }
            }
        }

        Mail::to($order->customer_email)->send(
            new BackorderCancellationMail($backorder)
        );

        return response()->json([
            'message' => 'Backorder cancelled and customer notified',
            'data' => $backorder->fresh()->load(['order', 'product']),
        ]);
    }

    /**
     * Public endpoint: verify payment token and show backorder details.
     */
    public function verifyToken($token)
    {
        $backorder = Backorder::with(['order', 'product'])
            ->where('payment_token', $token)
            ->first();

        if (!$backorder) {
            return response()->json(['message' => 'Invalid payment link'], 404);
        }

        if (!$backorder->isTokenValid()) {
            if ($backorder->status === 'notified') {
                $backorder->update(['status' => 'expired']);
            }
            return response()->json(['message' => 'This payment link has expired'], 410);
        }

        if ($backorder->status === 'paid') {
            return response()->json(['message' => 'This backorder has already been paid'], 422);
        }

        $product = $backorder->product;
        $stockAvailable = $product && $product->stock >= $backorder->quantity;

        $lineTotal = round((float) $product->price * $backorder->quantity, 2);
        $tax = $this->calculateBackorderTax($product, $backorder->quantity);
        $shipping = $this->calculateBackorderShipping($backorder);
        $total = round($lineTotal + $tax['tax_amount'] + $shipping['total'], 2);

        return response()->json([
            'data' => [
                'id' => $backorder->id,
                'product_name' => $product->name,
                'product_price' => $product->price,
                'quantity' => $backorder->quantity,
                'subtotal' => $lineTotal,
                'tax_amount' => $tax['tax_amount'],
                'tax_rate' => $tax['tax_rate'],
                'tax_name' => $tax['tax_name'],
                'shipping_amount' => $shipping['total'],
                'total' => $total,
                'customer_name' => $backorder->order->customer_name,
                'customer_email' => $backorder->order->customer_email,
                'order_id' => $backorder->order_id,
                'expires_at' => $backorder->token_expires_at->toIso8601String(),
                'stock_available' => $stockAvailable,
            ],
        ]);
    }

    /**
     * Public endpoint: pay for a backorder via token.
     */
    public function payByToken(Request $request, $token, PaymentService $payments)
    {
        $backorder = Backorder::with(['order', 'product'])
            ->where('payment_token', $token)
            ->first();

        if (!$backorder) {
            return response()->json(['message' => 'Invalid payment link'], 404);
        }

        if (!$backorder->isTokenValid()) {
            if ($backorder->status === 'notified') {
                $backorder->update(['status' => 'expired']);
            }
            return response()->json(['message' => 'This payment link has expired'], 410);
        }

        if ($backorder->status === 'paid') {
            return response()->json(['message' => 'This backorder has already been paid'], 422);
        }

        // Ensure stock is still available before accepting payment
        $product = $backorder->product;
        if (!$product || $product->stock < $backorder->quantity) {
            $available = $product->stock ?? 0;
            return response()->json([
                'message' => "Sorry, this item is no longer available in the required quantity. Available stock: {$available}. Please contact us for assistance.",
            ], 409);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:stripe,paypal,square',
        ]);

        $order = $backorder->order;
        $lineTotal = round((float) $product->price * $backorder->quantity, 2);
        $tax = $this->calculateBackorderTax($backorder->product, $backorder->quantity);
        $shipping = $this->calculateBackorderShipping($backorder);
        $chargeTotal = round($lineTotal + $tax['tax_amount'] + $shipping['total'], 2);

        $responseData = [
            'order_id' => $order->id,
            'amount' => $chargeTotal,
            'backorder_id' => $backorder->id,
        ];

        if ($validated['payment_method'] === 'stripe') {
            // Create Stripe PaymentIntent directly for backorder amount
            $gateway = app(\App\Payments\GatewayManager::class)->get('stripe');
            $init = $gateway->createPayment($order, [
                'amount_override' => $chargeTotal,
                'backorder_id' => $backorder->id,
            ]);

            $payment = $payments->createPending($order, 'stripe', array_merge($init, [
                'backorder_id' => $backorder->id,
            ]));

            $responseData['payment_id'] = $payment->id;
            $responseData['client_secret'] = $init['client_secret'];
        } else {
            // PayPal / Square: create payment through gateway to get redirect URL
            $provider = $validated['payment_method'];
            $gateway = app(\App\Payments\GatewayManager::class)->get($provider);
            $init = $gateway->createPayment($order, [
                'amount_override' => $chargeTotal,
                'backorder_id' => $backorder->id,
            ]);

            $payment = $payments->createPending($order, $provider, array_merge($init, [
                'backorder_id' => $backorder->id,
            ]));

            $responseData['payment_id'] = $payment->id;
            $responseData['redirect_url'] = $init['redirect_url'] ?? $init['approval_url'] ?? $init['checkout_url'] ?? $init['url'] ?? null;
        }

        return response()->json(['data' => $responseData]);
    }

    /**
     * Get backorder settings (global config).
     */
    public function settings()
    {
        $config = SiteConfig::first();

        return response()->json([
            'data' => [
                'backorder_enabled' => (bool) ($config?->backorder_enabled ?? false),
                'backorder_payment_link_expiry_hours' => (int) ($config?->backorder_payment_link_expiry_hours ?? 24),
            ],
        ]);
    }

    /**
     * Update backorder settings (global config).
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'backorder_enabled' => 'required|boolean',
            'backorder_payment_link_expiry_hours' => 'required|integer|min:1|max:720',
        ]);

        $config = SiteConfig::first() ?? SiteConfig::create([]);
        $config->update($validated);

        return response()->json([
            'message' => 'Backorder settings updated',
            'data' => [
                'backorder_enabled' => (bool) $config->backorder_enabled,
                'backorder_payment_link_expiry_hours' => (int) $config->backorder_payment_link_expiry_hours,
            ],
        ]);
    }

    /**
     * Calculate shipping for a backorder based on the original order's delivery details.
     */
    private function calculateBackorderShipping(Backorder $backorder): array
    {
        $order = $backorder->order;

        // No shipping for pickup orders or orders without address info
        if (!$order || $order->delivery_method === 'pickup' || !$order->country) {
            return ['total' => 0];
        }

        $product = $backorder->product;
        $weight = 0;
        $volumeCbm = 0;

        if ($product->shipping_calc_type === 'dimensions') {
            $volumeCbm = $product->volume_cbm * $backorder->quantity;
        } else {
            $weight = (float) ($product->weight ?? 0) * $backorder->quantity;
        }

        $lineTotal = round((float) $product->price * $backorder->quantity, 2);

        $calculator = app(\App\Services\ShippingCalculator::class);
        $result = $calculator->calculateShipping([
            'country' => $order->country,
            'state' => $order->state,
            'city' => $order->city,
            'weight' => $weight,
            'volume_cbm' => $volumeCbm,
            'order_amount' => $lineTotal,
            'options' => [],
        ]);

        if (isset($result['error'])) {
            return ['total' => 0];
        }

        return $result;
    }

    /**
     * Calculate tax for a backorder line item using the store's tax settings.
     */
    private function calculateBackorderTax(Product $product, int $quantity): array
    {
        $taxSetting = \App\Models\TaxSetting::first();

        if (!$taxSetting || !$taxSetting->tax_enabled || $taxSetting->tax_rate == 0) {
            return [
                'tax_amount' => 0,
                'tax_rate' => 0,
                'tax_name' => $taxSetting->tax_name ?? 'Tax',
            ];
        }

        $items = [['price' => (float) $product->price, 'quantity' => $quantity]];
        $result = $taxSetting->calculateCartTax($items);

        return [
            'tax_amount' => (float) ($result['tax_amount'] ?? 0),
            'tax_rate' => (float) ($result['tax_rate'] ?? 0),
            'tax_name' => $result['tax_name'] ?? 'Tax',
        ];
    }
}
