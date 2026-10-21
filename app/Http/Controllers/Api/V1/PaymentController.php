<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Modules\Payments\Application\PaymentFlow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function pay(Order $order, Request $request, PaymentFlow $payments)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['paypal', 'square'])],
        ]);

        return response()->json($payments->startRedirect($order, $request, $data['provider']));
    }

    public function stripeIntent(Order $order, Request $request, PaymentFlow $payments)
    {
        return response()->json($payments->startStripe($order, $request));
    }

    public function show(Request $request, Payment $payment, PaymentFlow $payments)
    {
        return response()->json($payments->status($request, $payment));
    }
}
