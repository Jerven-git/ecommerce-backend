<?php

namespace App\Modules\Realtime\Traits;

use App\Modules\Realtime\Events\ModelChanged;
use Illuminate\Support\Facades\Log;

trait BroadcastsChanges
{
    public static function bootBroadcastsChanges(): void
    {
        if (!config('realtime.enabled')) {
            return;
        }

        static::created(fn ($model) => self::safeBroadcast(
            $model, 'created', $model->toArray()
        ));

        static::updated(fn ($model) => self::safeBroadcast(
            $model, 'updated', $model->getDirty()
        ));

        static::deleted(fn ($model) => self::safeBroadcast(
            $model, 'deleted'
        ));
    }

    private static function safeBroadcast($model, string $action, array $data = []): void
    {
        try {
            ModelChanged::dispatch(
                class_basename($model),
                $model->getKey(),
                $action,
                $data
            );
        } catch (\Throwable $e) {
            Log::warning('Realtime: broadcast failed, model operation unaffected', [
                'model' => class_basename($model),
                'id' => $model->getKey(),
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
