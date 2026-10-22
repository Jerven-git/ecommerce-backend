<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Modules\Orders\Application\OrderCheckout;
use App\Modules\Orders\Application\OrderLifecycle;
use App\Modules\Orders\Application\OrderOperationException;
use App\Repositories\OrderRepository;
use App\Support\Cache\StoreCommerceCache;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderCheckout $checkout,
        private readonly OrderLifecycle $lifecycle,
        private readonly StoreCommerceCache $cache,
        private readonly CurrentStore $currentStore,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Order::query();

        $includes = array_filter(explode(',', $request->query('include', '')));
        $query->with(array_intersect($includes, ['items', 'payment', 'shipment']));

        $query->when($request->filled('status'), function ($query) use ($request) {
            $status = $request->query('status');
            if ($status === 'cancelled') {
                $query->whereIn('status', ['cancelled', 'backorder_cancelled']);
            } elseif ($status === 'backorder') {
                $query->where('status', 'like', 'backorder_%');
            } else {
                $query->where('status', $status);
            }
        })->search($request->query('search'));

        $allowedSorts = ['created_at', 'id', 'status', 'total'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'created_at';
        $direction = strtolower($request->query('order', 'desc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        $query->orderBy($sort, $direction);

        $statusCounts = $this->cache->rememberOrderStatusCounts(
            (int) $this->currentStore->id(),
            fn () => $this->orders->statusCounts(),
        );
        $paginator = $query->paginate(min((int) $request->input('per_page', 15), 100));

        return response()->json(array_merge($paginator->toArray(), [
            'status_counts' => $statusCounts,
        ]));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->orders->findWithAll($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->input('delivery_method') === 'gift_card') {
            return $this->storeGiftCard($request);
        }

        $validated = $this->validateOrder($request);
        $this->ensureDeliveryFields($validated);

        try {
            $result = $this->checkout->create(
                $validated,
                $request->input('discount_code'),
                $request->input('gift_card_code'),
            );

            return response()->json([
                'message' => 'Order created successfully',
                'data' => $result,
            ], 201);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Failed to create order',
                'error' => app()->hasDebugModeEnabled() ? $exception->getMessage() : 'Something went wrong',
            ], 422);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,backorder_awaiting_stock,backorder_notified,backorder_expired,backorder_cancelled',
        ]);

        try {
            $order = $this->lifecycle->updateStatus($id, $validated['status']);

            return response()->json([
                'message' => 'Order status updated successfully',
                'data' => $order,
            ]);
        } catch (OrderOperationException $exception) {
            return $this->operationError($exception);
        }
    }

    public function confirmPayment(int $id): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Payment confirmed',
                'data' => $this->lifecycle->confirmPayment($id),
            ]);
        } catch (OrderOperationException $exception) {
            return $this->operationError($exception);
        }
    }

    public function undoPayment(int $id): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Payment reverted to pending',
                'data' => $this->lifecycle->undoPayment($id),
            ]);
        } catch (OrderOperationException $exception) {
            return $this->operationError($exception);
        }
    }

    public function cancelAndRefund(int $id): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Order cancelled. Please process the refund through your payment provider.',
                'data' => $this->lifecycle->cancelAndRefund($id),
            ]);
        } catch (OrderOperationException $exception) {
            return $this->operationError($exception);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->lifecycle->delete($id);

            return response()->json(['message' => 'Order deleted successfully']);
        } catch (OrderOperationException $exception) {
            return $this->operationError($exception);
        }
    }

    private function storeGiftCard(Request $request): JsonResponse
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

        try {
            $order = $this->checkout->createGiftCard($validated);

            return response()->json([
                'message' => 'Gift card order created successfully',
                'data' => ['order' => $order],
            ], 201);
        } catch (OrderOperationException $exception) {
            return $this->operationError($exception);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Failed to create gift card order',
                'error' => app()->hasDebugModeEnabled() ? $exception->getMessage() : 'Something went wrong',
            ], 422);
        }
    }

    /** @return array<string, mixed> */
    private function validateOrder(Request $request): array
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
            'payment_method' => 'nullable|string|in:stripe,paypal,square',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.variant_id' => 'nullable|integer',
            'gift_card_code' => 'nullable|string|max:20',
        ]);
    }

    /** @param array<string, mixed> $validated */
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

    private function operationError(OrderOperationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
        ], $exception->status());
    }
}
