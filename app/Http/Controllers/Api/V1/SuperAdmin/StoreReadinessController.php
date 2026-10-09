<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Tenancy\StoreReadiness;
use Illuminate\Http\JsonResponse;

class StoreReadinessController extends Controller
{
    public function __invoke(Store $store, StoreReadiness $readiness): JsonResponse
    {
        return response()->json([
            'data' => $readiness->assess($store),
        ]);
    }
}
