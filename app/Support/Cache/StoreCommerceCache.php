<?php

namespace App\Support\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

class StoreCommerceCache
{
    private const DASHBOARD_RANGES = ['today', '7d', '30d', 'year'];

    public function rememberOrderStatusCounts(int $storeId, Closure $callback): array
    {
        return Cache::remember($this->orderStatusCountsKey($storeId), 300, $callback);
    }

    public function rememberDashboardStats(int $storeId, Closure $callback): array
    {
        return Cache::remember($this->dashboardStatsKey($storeId), 60, $callback);
    }

    public function rememberDashboardAnalytics(int $storeId, string $range, Closure $callback): array
    {
        return Cache::remember($this->dashboardAnalyticsKey($storeId, $range), 60, $callback);
    }

    public function forgetForStore(int $storeId): void
    {
        Cache::forget($this->orderStatusCountsKey($storeId));
        Cache::forget($this->dashboardStatsKey($storeId));

        foreach (self::DASHBOARD_RANGES as $range) {
            Cache::forget($this->dashboardAnalyticsKey($storeId, $range));
        }
    }

    private function orderStatusCountsKey(int $storeId): string
    {
        return "orders:store:{$storeId}:status-counts";
    }

    private function dashboardStatsKey(int $storeId): string
    {
        return "dashboard:store:{$storeId}:stats";
    }

    private function dashboardAnalyticsKey(int $storeId, string $range): string
    {
        return "dashboard:store:{$storeId}:analytics:{$range}";
    }
}
