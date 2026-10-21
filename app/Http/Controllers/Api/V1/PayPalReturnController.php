<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Scopes\StoreScope;
use App\Modules\Payments\Application\PayPalCapture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayPalReturnController extends Controller
{
    public function __construct(private readonly PayPalCapture $capture) {}

    public function capture(Request $request)
    {
        $request->validate(['token' => ['required', 'string']]);
        $payment = $this->capture->findOrFail(trim((string) $request->input('token')));
        $result = $this->capture->captureForApi($payment);

        if (! $result->successful) {
            return response()->json([
                'message' => 'PayPal capture failed',
                'payment_id' => $payment->id,
                'paypal_error' => $result->payload,
            ], 422);
        }

        if (! $result->completed) {
            return response()->json([
                'message' => 'PayPal capture not completed',
                'payment_id' => $payment->id,
                'status' => 'pending',
                'paypal_status' => data_get($result->payload, 'status'),
                'capture_status' => data_get($result->payload, 'purchase_units.0.payments.captures.0.status'),
            ], 422);
        }

        return response()->json($this->captureResponse($payment, $result->captureId));
    }

    public function return(Request $request)
    {
        $paypalOrderId = trim((string) $request->query('token'));
        if ($paypalOrderId === '') {
            return redirect($this->frontendUrl('/checkout/failed?reason=missing_token'));
        }

        $payment = $this->capture->find($paypalOrderId);
        if (! $payment) {
            return redirect($this->frontendUrl('/checkout/failed?reason=payment_not_found'));
        }

        if ($payment->status === 'paid') {
            return redirect($this->frontendUrl($this->paymentCompletePath($payment)));
        }

        try {
            $result = $this->capture->captureForReturn($payment);

            if (! $result->successful) {
                Log::warning('PayPal capture failed', [
                    'paypal_order_id' => $paypalOrderId,
                    'payment_id' => $payment->id,
                    'status' => $result->httpStatus,
                    'body' => $result->payload,
                ]);

                return redirect($this->frontendUrl('/checkout/failed?payment_id='.$payment->id));
            }

            return redirect($this->frontendUrl($this->paymentCompletePath($payment)));
        } catch (\Throwable $exception) {
            Log::warning('PayPal return capture exception', [
                'paypal_order_id' => $paypalOrderId,
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);

            return redirect($this->frontendUrl('/checkout/failed?payment_id='.$payment->id));
        }
    }

    public function cancel(Request $request)
    {
        return redirect($this->frontendUrl('/checkout?cancelled=1'));
    }

    private function frontendUrl(string $path): string
    {
        $base = rtrim(
            config('app.frontend_url') ?: env('FRONTEND_URL') ?: config('app.url'),
            '/',
        );

        return $base.$path;
    }

    private function paymentCompletePath(Payment $payment): string
    {
        return '/payment/complete?'.http_build_query([
            'payment_id' => $payment->id,
            'payment_token' => $payment->public_token,
        ]);
    }

    /** @return array{payment_id: int, payment_token: string|null, status: string, order_status: string|null, capture_id: mixed} */
    private function captureResponse(Payment $payment, mixed $captureId): array
    {
        return [
            'payment_id' => $payment->id,
            'payment_token' => $payment->public_token,
            'status' => 'paid',
            'order_status' => Order::withoutGlobalScope(StoreScope::class)
                ->whereKey($payment->order_id)
                ->value('status'),
            'capture_id' => $captureId,
        ];
    }
}
