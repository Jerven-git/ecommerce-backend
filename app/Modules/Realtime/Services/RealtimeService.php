<?php

namespace App\Modules\Realtime\Services;

use App\Modules\Realtime\Events\DeploymentNotification;
use App\Modules\Realtime\Events\ModelChanged;
use Illuminate\Support\Facades\Log;

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

        try {
            if ($file && file_exists($file)) {
                $content = file_get_contents($file);

                if ($content !== false) {
                    return trim($content);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Realtime: failed to read version file', [
                'file' => $file,
                'error' => $e->getMessage(),
            ]);
        }

        return config('app.version', '0.0.0');
    }

    public function setVersion(string $version): void
    {
        $file = config('realtime.version_file');

        try {
            $dir = dirname($file);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($file, $version);
        } catch (\Throwable $e) {
            Log::error('Realtime: failed to write version file', [
                'file' => $file,
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
