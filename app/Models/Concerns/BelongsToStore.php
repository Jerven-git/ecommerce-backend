<?php

namespace App\Models\Concerns;

use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope);

        static::creating(function ($model): void {
            if (! empty($model->store_id)) {
                return;
            }

            $current = app(CurrentStore::class);

            if ($current->isSet()) {
                $model->store_id = $current->id();
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('store_id')) {
                throw new LogicException(sprintf(
                    '%s tenant ownership cannot be changed after creation.',
                    $model::class,
                ));
            }
        });
    }

    /**
     * Route-bound tenant records fail closed when middleware has not resolved a
     * tenant. Super Admin control-plane routes bind Store/User directly and do
     * not rely on this tenant model binding path.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = $query instanceof Model ? $query->newQuery() : $query;
        $current = app(CurrentStore::class);

        if (! $current->isSet()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where($this->qualifyColumn('store_id'), $current->id())
            ->where($field ?? $this->getRouteKeyName(), $value);
    }

    public function scopeForStore(Builder $query, Store|int $store): Builder
    {
        $storeId = $store instanceof Store ? $store->getKey() : $store;

        return $query->withoutGlobalScope(StoreScope::class)
            ->where($this->qualifyColumn('store_id'), $storeId);
    }

    public function isOwnedBy(Store|int $store): bool
    {
        $storeId = $store instanceof Store ? $store->getKey() : $store;

        return (int) $this->store_id === (int) $storeId;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
