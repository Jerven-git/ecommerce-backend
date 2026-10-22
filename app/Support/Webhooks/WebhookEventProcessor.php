<?php

namespace App\Support\Webhooks;

use App\Support\Database\Transaction;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookEventProcessor
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function process(Model $event, Closure $callback, array $context = []): bool
    {
        $failure = null;

        $processed = Transaction::run(function () use ($event, $callback, $context, &$failure): bool {
            $failure = null;
            $locked = $event->newQuery()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->getAttribute('processed_at') !== null) {
                return false;
            }

            $attempt = (int) $locked->getAttribute('attempts') + 1;
            $locked->forceFill(['attempts' => $attempt])->save();

            try {
                DB::transaction($callback);

                $locked->forceFill([
                    'processed_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                $locked->forceFill([
                    'failed_at' => now(),
                    'last_error' => $exception::class,
                ])->save();
                $failure = $exception;

                Log::error('Webhook processing failed', array_merge($context, [
                    'event_id' => $locked->getAttribute('event_id'),
                    'event_type' => $locked->getAttribute('event_type'),
                    'attempt' => $attempt,
                    'exception' => $exception::class,
                ]));
            }

            return true;
        });

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return $processed;
    }
}
