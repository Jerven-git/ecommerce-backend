<?php

namespace App\Modules\Realtime\Traits;

use App\Modules\Realtime\Events\ModelChanged;
use Illuminate\Support\Facades\Log;

trait BroadcastsChanges
{
    public static function bootBroadcastsChanges(): void
    {
        if (! config('realtime.enabled')) {
            return;
        }

        // Broadcast only the changed field NAMES, never attribute values.
        // Reverb enforces the Pusher ~10KB per-event limit, and large models
        // (e.g. SiteConfig with its homepage/theme JSON) blow past it, so the
        // event silently fails to publish. The `ssu.updates` channel is also
        // public, so shipping raw model values would leak data to every
        // connected visitor. Clients treat this as a signal and re-fetch the
        // record through the (scoped, authorized) API instead.
        static::created(fn ($model) => self::safeBroadcast($model, 'created'));

        static::updated(fn ($model) => self::safeBroadcast(
            $model, 'updated', ['changed' => array_keys($model->getDirty())]
        ));

        static::deleted(fn ($model) => self::safeBroadcast($model, 'deleted'));
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
