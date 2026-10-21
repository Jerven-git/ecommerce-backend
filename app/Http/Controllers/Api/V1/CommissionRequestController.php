<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommissionRequest;
use App\Models\CommissionRequest;
use App\Modules\Engagement\Application\CommissionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommissionRequestController extends Controller
{
    public function __construct(private readonly CommissionManager $commissions) {}

    public function store(StoreCommissionRequest $request): JsonResponse
    {
        $this->abortIfModuleDisabled();

        $validated = $request->validated();

        $commission = $this->commissions->submit($validated, $request->file('reference_image'));

        return response()->json([
            'message' => 'Your commission request has been submitted. We will contact you within 48 hours.',
            'data' => ['id' => $commission->id],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->commissions->paginate($request->only(['status', 'search', 'per_page'])));
    }

    public function show(CommissionRequest $commissionRequest): JsonResponse
    {
        return response()->json(['data' => $commissionRequest]);
    }

    public function update(Request $request, CommissionRequest $commissionRequest): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(CommissionRequest::STATUSES)],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json(['data' => $this->commissions->update($commissionRequest, $validated)]);
    }

    public function destroy(CommissionRequest $commissionRequest): JsonResponse
    {
        $this->commissions->delete($commissionRequest);

        return response()->json(['message' => 'Commission request deleted.']);
    }

    /**
     * Aborts the request when the commissions module is disabled on this
     * tenant — applied only to the public submit so admins can still review
     * past submissions even after the module is turned off.
     */
    private function abortIfModuleDisabled(): void
    {
        if (! $this->commissions->moduleEnabled()) {
            abort(404);
        }
    }
}
