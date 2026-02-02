<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\GatewayManager;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function pay(Order $order, Request $request, GatewayManager $manager, PaymentService $payments)
    {
Log::info('PAY DEBUG', [
    'route_order_param' => request()->route('order'),
    'order_id' => $order->id,
    'exists' => $order->exists,
]);
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['stripe','paypal','square'])],
        ]);

        $gateway = $manager->get($data['provider']);

        $init = $gateway->createPayment($order, [
            'user_id' => (string) optional($request->user())->id,
        ]);

        $payments->createPending($order, $data['provider'], $init);

        return response()->json($init);
    }
}
