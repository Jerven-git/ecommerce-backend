<?php

namespace App\Modules\Realtime\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class DeploymentNotification extends BaseRealtimeEvent implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $version,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel(config('realtime.channels.updates'))];
    }

    public function broadcastAs(): string
    {
        return 'deployment.new';
    }

    protected function payload(): array
    {
        return [
            'version' => $this->version,
            'timestamp' => now()->toISOString(),
        ];
    }
}
