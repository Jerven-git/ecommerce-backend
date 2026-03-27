<?php

namespace App\Modules\Realtime\Traits;

use App\Modules\Realtime\Events\ModelChanged;

trait BroadcastsChanges
{
    public static function bootBroadcastsChanges(): void
    {
        if (!config('realtime.enabled')) {
            return;
        }

        static::created(fn ($model) => ModelChanged::dispatch(
            class_basename($model),
            $model->getKey(),
            'created',
            $model->toArray()
        ));

        static::updated(fn ($model) => ModelChanged::dispatch(
            class_basename($model),
            $model->getKey(),
            'updated',
            $model->getDirty()
        ));

        static::deleted(fn ($model) => ModelChanged::dispatch(
            class_basename($model),
            $model->getKey(),
            'deleted'
        ));
    }
}
