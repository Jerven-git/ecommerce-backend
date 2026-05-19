<?php

namespace App\Models;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if ($activity->store_id !== null) {
                return;
            }

            $current = app(CurrentStore::class);

            if ($current->isSet()) {
                $activity->store_id = $current->id();
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeForStore(Builder $query, ?int $storeId): Builder
    {
        return $storeId === null ? $query : $query->where('store_id', $storeId);
    }
}
