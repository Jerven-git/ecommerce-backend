<?php

namespace App\Modules\Realtime\Console;

use App\Modules\Realtime\Services\RealtimeService;
use Illuminate\Console\Command;

class NotifyDeploymentCommand extends Command
{
    protected $signature = 'realtime:notify-deployment {version?}';

    protected $description = 'Broadcast a deployment notification to all connected clients';

    public function handle(RealtimeService $service): int
    {
        $version = $this->argument('version') ?? date('YmdHis');
        $service->setVersion($version);
        $service->notifyDeployment();
        $this->info("Deployment notification sent for version: {$version}");

        return 0;
    }
}
