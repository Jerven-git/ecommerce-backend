<?php

namespace App\Modules\Realtime\Services;

use App\Modules\Realtime\Events\DeploymentNotification;
use App\Modules\Realtime\Events\ModelChanged;

class RealtimeService
{
    public function notifyModelChanged(string $model, int|string $id, string $action, array $data = []): void
    {
        if (!config('realtime.enabled')) {
            return;
        }

        ModelChanged::dispatch($model, $id, $action, $data);
    }

    public function notifyDeployment(): void
    {
        $version = $this->getCurrentVersion();
        DeploymentNotification::dispatch($version);
    }

    public function getCurrentVersion(): string
    {
        $file = config('realtime.version_file');

        if (file_exists($file)) {
            return trim(file_get_contents($file));
        }

        return config('app.version', '0.0.0');
    }

    public function setVersion(string $version): void
    {
        $dir = dirname(config('realtime.version_file'));

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(config('realtime.version_file'), $version);
    }
}
