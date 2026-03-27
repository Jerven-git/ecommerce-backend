<?php

namespace App\Modules\Realtime\Events;

use Illuminate\Broadcasting\Channel;

class ModelChanged extends BaseRealtimeEvent
{
    public function __construct(
        public readonly string $modelType,
        public readonly int|string $modelId,
        public readonly string $action,
        public readonly array $data = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel(config('realtime.channels.updates'))];
    }

    public function broadcastAs(): string
    {
        return 'model.changed';
    }

    protected function payload(): array
    {
        return [
            'model' => $this->modelType,
            'id' => $this->modelId,
            'action' => $this->action,
            'data' => $this->data,
            'timestamp' => now()->toISOString(),
        ];
    }
}
