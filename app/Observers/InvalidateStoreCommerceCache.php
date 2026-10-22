<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Cache\StoreCommerceCache;
use Illuminate\Database\Eloquent\Model;

class InvalidateStoreCommerceCache
{
    public function __construct(private readonly StoreCommerceCache $cache) {}

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $storeId = $model instanceof Payment
            ? Order::withoutGlobalScopes()->whereKey($model->order_id)->value('store_id')
            : $model->getAttribute('store_id');

        if ($storeId !== null) {
            $this->cache->forgetForStore((int) $storeId);
        }
    }
}
