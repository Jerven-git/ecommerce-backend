<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\Store;
use App\Support\WebsiteEditor\WebsiteEditorAudit;
use App\Support\WebsiteEditor\WebsiteEditorWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteConfigDraftReviewController extends Controller
{
    public function __construct(
        private readonly WebsiteEditorAudit $audit,
        private readonly WebsiteEditorWorkflow $workflow,
    ) {}

    public function requestReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draft_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->updateDraft($request, function (SiteConfigDraft $draft) use ($request, $validated): void {
            $draft->update([
                'review_status' => 'pending',
                'review_requested_by' => $request->user()->id,
                'review_requested_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => $this->trimmed($validated['note'] ?? null),
                'scheduled_publish_at' => null,
                'scheduled_by' => null,
                'scheduled_release_note' => null,
            ]);
        }, 'website_review_requested', 'Review requested.');
    }

    public function approve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draft_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->updateDraft($request, function (SiteConfigDraft $draft) use ($request, $validated): void {
            $this->requireStatus($draft, 'pending', 'Only a draft awaiting review can be approved.');
            $draft->update([
                'review_status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $this->trimmed($validated['note'] ?? null),
            ]);
        }, 'website_review_approved', 'Draft approved.');
    }

    public function requestChanges(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draft_version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        return $this->updateDraft($request, function (SiteConfigDraft $draft) use ($request, $validated): void {
            $this->requireStatus($draft, 'pending', 'Only a draft awaiting review can be returned for changes.');
            $draft->update([
                'review_status' => 'changes_requested',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => trim($validated['note']),
                'scheduled_publish_at' => null,
                'scheduled_by' => null,
                'scheduled_release_note' => null,
            ]);
        }, 'website_changes_requested', 'Changes requested.');
    }

    public function schedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draft_version' => ['required', 'integer', 'min:1'],
            'scheduled_for' => ['required', 'date_format:Y-m-d\\TH:i'],
            'release_note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->updateDraft($request, function (SiteConfigDraft $draft, Store $store) use ($request, $validated): void {
            $this->requireStatus($draft, 'approved', 'Approve the draft before scheduling publication.');

            $scheduledAt = CarbonImmutable::createFromFormat(
                'Y-m-d\\TH:i',
                $validated['scheduled_for'],
                $store->resolvedTimezone(),
            );

            if (! $scheduledAt || ! $scheduledAt->isFuture()) {
                throw ValidationException::withMessages([
                    'scheduled_for' => ['Choose a future date and time in the store timezone.'],
                ]);
            }

            $draft->update([
                'scheduled_publish_at' => $scheduledAt->utc(),
                'scheduled_by' => $request->user()->id,
                'scheduled_release_note' => $this->trimmed($validated['release_note'] ?? null),
            ]);
        }, 'website_publish_scheduled', 'Publication scheduled.');
    }

    public function cancelSchedule(Request $request): JsonResponse
    {
        $request->validate(['draft_version' => ['required', 'integer', 'min:1']]);

        return $this->updateDraft($request, function (SiteConfigDraft $draft): void {
            if (! $draft->scheduled_publish_at) {
                throw ValidationException::withMessages([
                    'schedule' => ['This draft does not have a scheduled publication.'],
                ]);
            }

            $draft->update([
                'scheduled_publish_at' => null,
                'scheduled_by' => null,
                'scheduled_release_note' => null,
            ]);
        }, 'website_publish_schedule_cancelled', 'Scheduled publication cancelled.');
    }

    /**
     * @param callable(SiteConfigDraft, Store): void $mutation
     */
    private function updateDraft(Request $request, callable $mutation, string $event, string $message): JsonResponse
    {
        return DB::transaction(function () use ($request, $mutation, $event, $message): JsonResponse {
            $storeId = (int) $request->user()->store_id;
            $store = Store::query()->findOrFail($storeId);
            $config = SiteConfig::withoutGlobalScope(StoreScope::class)->where('store_id', $storeId)->firstOrFail();
            $draft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (! $draft) {
                return response()->json(['message' => 'Save a private draft before using review controls.'], 404);
            }

            if ($request->integer('draft_version') !== $draft->lock_version) {
                return response()->json([
                    'message' => 'This private draft changed in another editor. Refresh before updating its review status.',
                    'code' => 'draft_workflow_conflict',
                ], 409);
            }

            $mutation($draft, $store);
            $draft = $draft->fresh();
            $this->audit->record($event, $config, $request->user(), [
                'draft_id' => $draft->id,
                'review_status' => $draft->review_status,
                'scheduled_publish_at' => $draft->scheduled_publish_at?->toJSON(),
            ]);

            return response()->json([
                'data' => $this->workflow->serialize($draft, $store),
                'message' => $message,
            ]);
        });
    }

    private function requireStatus(SiteConfigDraft $draft, string $status, string $message): void
    {
        if ($draft->review_status !== $status) {
            throw ValidationException::withMessages(['review_status' => [$message]]);
        }
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
