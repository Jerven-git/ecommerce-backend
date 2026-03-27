<?php

namespace App\Modules\Realtime\Http\Controllers;

use App\Modules\Realtime\Services\RealtimeService;
use Illuminate\Http\JsonResponse;

class VersionController
{
    public function __invoke(RealtimeService $service): JsonResponse
    {
        return response()->json([
            'version' => $service->getCurrentVersion(),
            'timestamp' => now()->toISOString(),
        ]);
    }
}
