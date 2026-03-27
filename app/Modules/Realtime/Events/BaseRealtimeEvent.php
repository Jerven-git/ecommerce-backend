<?php

namespace App\Modules\Realtime\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class BaseRealtimeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    abstract public function broadcastOn(): array;

    public function broadcastWith(): array
    {
        return $this->payload();
    }

    abstract protected function payload(): array;
}
