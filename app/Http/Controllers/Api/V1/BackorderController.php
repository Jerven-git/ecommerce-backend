<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Backorders\Application\BackorderCheckout;
use App\Modules\Backorders\Application\BackorderLifecycle;
use App\Modules\Backorders\Application\BackorderOperationException;
use App\Modules\Backorders\Application\BackorderQuery;
use App\Modules\Backorders\Application\BackorderSettings;
use Illuminate\Http\Request;

class BackorderController extends Controller
{
    public function index(Request $request, BackorderQuery $backorders)
    {
        return response()->json($backorders->paginate(
            $request->only(['status', 'product_id', 'order_id', 'search']),
            (int) $request->input('per_page', 15),
        ));
    }

    public function show(int|string $id, BackorderQuery $backorders)
    {
        return response()->json([
            'data' => $backorders->find($id, ['order.items', 'product']),
        ]);
    }

    public function notify(int|string $id, BackorderLifecycle $backorders)
    {
        return $this->execute(function () use ($id, $backorders) {
            return response()->json([
                'message' => 'Customer notified with payment link',
                'data' => $backorders->notify($id),
            ]);
        });
    }

    public function resend(int|string $id, BackorderLifecycle $backorders)
    {
        return $this->execute(function () use ($id, $backorders) {
            return response()->json([
                'message' => 'Payment link resent to customer',
                'data' => $backorders->resend($id),
            ]);
        });
    }

    public function cancel(int|string $id, BackorderLifecycle $backorders)
    {
        return $this->execute(function () use ($id, $backorders) {
            return response()->json([
                'message' => 'Backorder cancelled and customer notified',
                'data' => $backorders->cancel($id),
            ]);
        });
    }

    public function verifyToken(string $token, BackorderCheckout $checkout)
    {
        return $this->execute(fn () => response()->json([
            'data' => $checkout->details($token),
        ]));
    }

    public function payByToken(Request $request, string $token, BackorderCheckout $checkout)
    {
        return $this->execute(function () use ($request, $token, $checkout) {
            $backorder = $checkout->paymentBackorder($token);
            $validated = $request->validate([
                'payment_method' => 'required|string|in:stripe,paypal,square',
            ]);

            return response()->json([
                'data' => $checkout->startPayment($backorder, $validated['payment_method'], $request),
            ]);
        });
    }

    public function confirmWithoutPayment(string $token, BackorderCheckout $checkout)
    {
        return $this->execute(function () use ($token, $checkout) {
            $checkout->confirmWithoutPayment($token);

            return response()->json([
                'message' => 'Order confirmed. You will be contacted for payment.',
                'data' => ['status' => 'confirmed'],
            ]);
        });
    }

    public function markAsPaid(int|string $id, BackorderLifecycle $backorders)
    {
        return $this->execute(function () use ($id, $backorders) {
            return response()->json([
                'message' => 'Backorder marked as paid',
                'data' => $backorders->markAsPaid($id),
            ]);
        });
    }

    public function settings(BackorderSettings $settings)
    {
        return response()->json(['data' => $settings->read()]);
    }

    public function updateSettings(Request $request, BackorderSettings $settings)
    {
        $validated = $request->validate([
            'backorder_enabled' => 'required|boolean',
            'backorder_payment_link_expiry_hours' => 'required|integer|min:1|max:720',
        ]);

        return response()->json([
            'message' => 'Backorder settings updated',
            'data' => $settings->update($validated),
        ]);
    }

    private function execute(callable $operation)
    {
        try {
            return $operation();
        } catch (BackorderOperationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->status,
            );
        }
    }
}
