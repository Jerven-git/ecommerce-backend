<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Modules\ControlPlane\Application\OverviewQuery;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __construct(private readonly OverviewQuery $overview) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => $this->overview->get()]);
    }
}
