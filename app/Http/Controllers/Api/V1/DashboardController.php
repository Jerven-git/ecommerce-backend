<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $stats = Cache::remember('dashboard_stats', 60, function () {
            $orderStats = Order::toBase()
                ->selectRaw('count(*) as total_orders')
                ->selectRaw("count(case when status = 'pending' then 1 end) as pending_orders")
                ->first();

            $revenueStats = DB::table('orders')
                ->join('payments', 'orders.id', '=', 'payments.order_id')
                ->selectRaw("coalesce(sum(case when payments.status = 'paid' then orders.total_amount else 0 end), 0) as total_revenue")
                ->selectRaw("coalesce(sum(case when payments.status = 'pending' then orders.total_amount else 0 end), 0) as pending_revenue")
                ->first();

            $totalProducts = Product::count();

            return [
                'total_products' => $totalProducts,
                'total_orders' => (int) $orderStats->total_orders,
                'pending_orders' => (int) $orderStats->pending_orders,
                'total_revenue' => round((float) $revenueStats->total_revenue, 2),
                'pending_revenue' => round((float) $revenueStats->pending_revenue, 2),
            ];
        });

        $recentOrders = Order::with('payment')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'recent_orders' => $recentOrders,
            ],
        ]);
    }
}
