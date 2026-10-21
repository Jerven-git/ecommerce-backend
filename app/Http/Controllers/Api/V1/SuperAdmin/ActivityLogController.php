<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Modules\ControlPlane\Application\ActivityQuery;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityQuery $activities) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'causer_id' => ['nullable', 'integer'],
            'log_name' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $isSuperAdmin = $user?->isSuperAdmin() ?? false;

        $storeId = $isSuperAdmin
            ? ($request->filled('store_id') ? (int) $request->input('store_id') : null)
            : app(CurrentStore::class)->id();

        return response()->json($this->activities->paginate($request->all(), $storeId));
    }
}
