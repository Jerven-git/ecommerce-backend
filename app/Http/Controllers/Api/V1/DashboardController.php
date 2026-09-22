<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Support\Tenancy\CurrentStore;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $storeId = app(CurrentStore::class)->id();

        $range = $request->query('range', '7d');
        $range = strtolower((string) $range);
        if ($range === 'this_year') {
            $range = 'year';
        }
        if (! in_array($range, ['today', '7d', '30d', 'year'], true)) {
            $range = '7d';
        }

        $stats = Cache::remember("dashboard_stats:store:{$storeId}", 60, function () use ($storeId) {
            $orderStats = Order::toBase()
                ->selectRaw('count(*) as total_orders')
                ->selectRaw("count(case when status = 'pending' then 1 end) as pending_orders")
                ->first();

            $revenueStats = DB::table('orders')
                ->join('payments', 'orders.id', '=', 'payments.order_id')
                ->where('orders.store_id', $storeId)
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

        [$start, $end, $granularity] = $this->resolveRange($range);

        $analytics = Cache::remember("dashboard_analytics:store:{$storeId}:range:{$range}", 60, function () use ($storeId, $range, $start, $end, $granularity) {
            return $this->buildAnalytics($storeId, $range, $start, $end, $granularity);
        });

        $recentOrders = Order::with('payment')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'analytics' => $analytics,
                'recent_orders' => $recentOrders,
            ],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveRange(string $range): array
    {
        $now = Carbon::now();

        return match ($range) {
            'today' => [Carbon::today(), $now, 'hour'],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now, 'day'],
            'year' => [$now->copy()->startOfYear(), $now, 'month'],
            default => [$now->copy()->subDays(6)->startOfDay(), $now, 'day'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAnalytics(int $storeId, string $range, Carbon $start, Carbon $end, string $granularity): array
    {
        $buckets = $this->buildBuckets($range, $start, $end, $granularity);
        $keys = array_column($buckets, 'key');

        // Fetch grouped orders / revenue (expressions are driver-aware so
        // tests on SQLite and production on MySQL share the same code path).
        [$orderGroups, $revenueGroups] = $this->groupedOrdersAndRevenue($storeId, $start, $end, $granularity);

        $series = [];
        $totalOrders = 0;
        $totalRevenue = 0.0;

        foreach ($buckets as $b) {
            $k = $b['key'];
            $orders = (int) ($orderGroups[$k] ?? 0);
            $revenue = round((float) ($revenueGroups[$k] ?? 0), 2);
            $totalOrders += $orders;
            $totalRevenue += $revenue;
            $series[] = [
                'key' => $k,
                'label' => $b['label'],
                'date' => $b['date'],
                'orders' => $orders,
                'revenue' => $revenue,
            ];
        }

        $totalRevenue = round($totalRevenue, 2);
        $aov = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0.0;

        // Popular products (SSU products have image_url but no thumb column)
        $popular = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.store_id', $storeId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->whereNotIn('orders.status', ['cancelled', 'backorder_cancelled', 'backorder_expired'])
            ->groupBy('order_items.product_id', 'order_items.product_name', 'products.image_url')
            ->selectRaw('order_items.product_id as product_id')
            ->selectRaw('MIN(order_items.product_name) as name')
            ->selectRaw("COALESCE(MIN(products.image_url), '') as image_url")
            ->selectRaw("'' as thumb_url")
            ->selectRaw('SUM(order_items.quantity) as total_quantity')
            ->selectRaw('SUM(order_items.subtotal) as total_revenue')
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                return [
                    'product_id' => (int) $row->product_id,
                    'name' => (string) $row->name,
                    'image_url' => (string) $row->image_url,
                    'thumb_url' => (string) $row->thumb_url,
                    'quantity_sold' => (int) $row->total_quantity,
                    'revenue' => round((float) $row->total_revenue, 2),
                    'order_count' => (int) $row->order_count,
                ];
            })
            ->all();

        $totalProducts = Product::count();

        return [
            'range' => $range,
            'start_date' => $start->toIso8601String(),
            'end_date' => $end->toIso8601String(),
            'granularity' => $granularity,
            'summary' => [
                'orders' => $totalOrders,
                'revenue' => $totalRevenue,
                'aov' => $aov,
                'products' => $totalProducts,
            ],
            'series' => $series,
            'popular_products' => $popular,
        ];
    }

    /**
     * Grouped order counts and paid revenue keyed by bucket key.
     *
     * @return array{0: array, 1: array}
     */
    private function groupedOrdersAndRevenue(int $storeId, Carbon $start, Carbon $end, string $granularity): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        $orderColumn = match ($granularity) {
            'hour' => $sqlite ? "CAST(strftime('%H', created_at) AS INTEGER)" : 'HOUR(created_at)',
            'month' => $sqlite ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')",
            default => 'DATE(created_at)',
        };
        $revenueColumn = match ($granularity) {
            'hour' => $sqlite ? "CAST(strftime('%H', orders.created_at) AS INTEGER)" : 'HOUR(orders.created_at)',
            'month' => $sqlite ? "strftime('%Y-%m', orders.created_at)" : "DATE_FORMAT(orders.created_at, '%Y-%m')",
            default => 'DATE(orders.created_at)',
        };

        $orderGroups = Order::whereBetween('created_at', [$start, $end])
            ->where('store_id', $storeId)
            ->selectRaw("{$orderColumn} as k, count(*) as cnt")
            ->groupBy('k')
            ->pluck('cnt', 'k')
            ->all();

        $revenueGroups = DB::table('orders')
            ->join('payments', 'orders.id', '=', 'payments.order_id')
            ->where('orders.store_id', $storeId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('payments.status', 'paid')
            ->selectRaw("{$revenueColumn} as k, COALESCE(SUM(orders.total_amount),0) as rev")
            ->groupBy('k')
            ->pluck('rev', 'k')
            ->all();

        return [$orderGroups, $revenueGroups];
    }

    /**
     * @return list<array{key: string, label: string, date: string}>
     */
    private function buildBuckets(string $range, Carbon $start, Carbon $end, string $granularity): array
    {
        $buckets = [];

        if ($granularity === 'hour') {
            // 24 hourly buckets for today
            $cursor = $start->copy()->startOfDay();
            for ($h = 0; $h < 24; $h++) {
                $buckets[] = [
                    'key' => (string) $h,
                    'label' => sprintf('%02d:00', $h),
                    'date' => $cursor->copy()->addHours($h)->toDateString().'T'.sprintf('%02d:00:00', $h),
                ];
            }

            return $buckets;
        }

        if ($granularity === 'month') {
            $cursor = $start->copy()->startOfMonth();
            $last = $end->copy()->startOfMonth();
            while ($cursor->lte($last)) {
                $key = $cursor->format('Y-m');
                $buckets[] = [
                    'key' => $key,
                    'label' => $cursor->format('M'),
                    'date' => $cursor->format('Y-m-d'),
                ];
                $cursor->addMonth();
            }

            return $buckets;
        }

        // day
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m-d');
            $buckets[] = [
                'key' => $key,
                'label' => $cursor->format('M j'),
                'date' => $key,
            ];
            $cursor->addDay();
        }

        return $buckets;
    }
}
