<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentStore::class);

        if (! $current->isSet()) {
            return;
        }

        $builder->where($model->qualifyColumn('store_id'), $current->id());
    }
}
