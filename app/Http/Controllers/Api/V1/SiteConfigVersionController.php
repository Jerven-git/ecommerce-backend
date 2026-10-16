<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSiteConfigDraftRequest;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\SiteConfigVersion;
use App\Models\Store;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteEditorAssets;
use App\Support\WebsiteEditor\WebsiteEditorAudit;
use App\Support\WebsiteEditor\WebsiteEditorWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SiteConfigVersionController extends Controller
{
    public function __construct(
        private readonly WebsiteEditorAudit $audit,
        private readonly WebsiteEditorAssets $assets,
        private readonly WebsiteEditorWorkflow $workflow,
    ) {}

    public function index(): JsonResponse
    {
        $config = $this->configForAuthenticatedStore();
        $versions = SiteConfigVersion::withoutGlobalScope(StoreScope::class)
            ->with('publisher:id,name')
            ->where('store_id', $config->store_id)
            ->latest('id')
            ->limit(25)
            ->get(['id', 'published_by', 'release_note', 'changed_areas', 'created_at'])
            ->map(fn (SiteConfigVersion $version): array => [
                'id' => $version->id,
                'created_at' => $version->created_at?->toJSON(),
                'publisher_name' => $version->publisher?->name,
                'release_note' => $version->release_note,
                'changed_areas' => $version->changed_areas ?? [],
            ]);

        return response()->json([
            'data' => $versions,
            'published_hash' => HomepageSnapshot::hash(HomepageSnapshot::fromConfig($config)),
        ]);
    }

    public function show(int $version): JsonResponse
    {
        $config = $this->configForAuthenticatedStore();
        $snapshot = SiteConfigVersion::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $config->store_id)
            ->findOrFail($version);

        return response()->json([
            'data' => [
                'id' => $snapshot->id,
                'created_at' => $snapshot->created_at?->toJSON(),
                'payload' => $snapshot->payload,
                'publisher_name' => $snapshot->publisher?->name,
                'release_note' => $snapshot->release_note,
                'changed_areas' => $snapshot->changed_areas ?? [],
            ],
            'published_hash' => HomepageSnapshot::hash(HomepageSnapshot::fromConfig($config)),
        ]);
    }

    public function restore(Request $request, int $version): JsonResponse
    {
        $validated = $request->validate([
            'base_hash' => ['required', 'string', 'size:64'],
            'draft_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $storeId = (int) $request->user()->store_id;

        try {
            return DB::transaction(function () use ($request, $validated, $version, $storeId): JsonResponse {
                $config = SiteConfig::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $snapshot = SiteConfigVersion::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->findOrFail($version);
                $existingDraft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->lockForUpdate()
                    ->first();
                $currentPayload = HomepageSnapshot::fromConfig($config);

                if (! hash_equals(HomepageSnapshot::hash($currentPayload), $validated['base_hash'])) {
                    $this->audit->rejected('restore', 'version_restore_conflict', $storeId, $request->user()->id, [
                        'restored_version_id' => $snapshot->id,
                    ]);

                    return response()->json([
                        'message' => 'The published website changed while this version was being previewed. Refresh the history before restoring it.',
                        'code' => 'version_restore_conflict',
                    ], 409);
                }

                $submittedDraftVersion = $validated['draft_version'] ?? null;
                if (($existingDraft && $submittedDraftVersion !== $existingDraft->lock_version)
                    || (! $existingDraft && $submittedDraftVersion !== null)) {
                    $this->audit->rejected('restore', 'version_restore_draft_conflict', $storeId, $request->user()->id, [
                        'restored_version_id' => $snapshot->id,
                    ]);

                    return response()->json([
                        'message' => 'The private draft changed while this version was being previewed. Review the latest draft before restoring.',
                        'code' => 'version_restore_draft_conflict',
                    ], 409);
                }

                Validator::make(
                    ['payload' => $snapshot->payload],
                    SaveSiteConfigDraftRequest::payloadRules(),
                )->validate();

                $payload = HomepageSnapshot::forDraft(
                    $this->assets->normalizePayload($config, $snapshot->payload),
                );

                if ($existingDraft) {
                    $existingDraft->update([
                        'payload' => $payload,
                        'base_updated_at' => $config->updated_at,
                        'base_hash' => HomepageSnapshot::hash($currentPayload),
                        'lock_version' => $existingDraft->lock_version + 1,
                        'updated_by' => $request->user()->id,
                        'review_status' => 'draft',
                        'review_requested_by' => null,
                        'review_requested_at' => null,
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                        'review_note' => null,
                        'scheduled_publish_at' => null,
                        'scheduled_by' => null,
                        'scheduled_release_note' => null,
                    ]);
                    $draft = $existingDraft->fresh();
                } else {
                    $draft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)->create([
                        'store_id' => $storeId,
                        'payload' => $payload,
                        'base_updated_at' => $config->updated_at,
                        'base_hash' => HomepageSnapshot::hash($currentPayload),
                        'lock_version' => 1,
                        'created_by' => $request->user()->id,
                        'updated_by' => $request->user()->id,
                    ]);
                }

                $this->assets->purgeUnusedDraftAssets($config, $draft->payload);
                $this->audit->record('version_restored_to_draft', $config, $request->user(), [
                    'restored_version_id' => $snapshot->id,
                    'draft_id' => $draft->id,
                    'draft_version' => $draft->lock_version,
                ]);

                return response()->json([
                    'data' => [
                        'payload' => $draft->payload,
                        'base_updated_at' => $draft->base_updated_at?->toJSON(),
                        'has_draft' => true,
                        'draft_updated_at' => $draft->updated_at?->toJSON(),
                        'draft_version' => $draft->lock_version,
                        'workflow' => $this->workflow->serialize($draft, Store::query()->findOrFail($config->store_id)),
                    ],
                    'message' => 'Previous website version restored to the private draft.',
                ]);
            });
        } catch (Throwable $exception) {
            $this->audit->failed('restore', $storeId, $request->user()->id, $exception, [
                'restored_version_id' => $version,
            ]);

            throw $exception;
        }
    }

    public function destroy(Request $request, int $version): JsonResponse
    {
        $storeId = (int) $request->user()->store_id;

        try {
            return DB::transaction(function () use ($request, $version, $storeId): JsonResponse {
                $config = SiteConfig::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->firstOrFail();
                $snapshot = SiteConfigVersion::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->lockForUpdate()
                    ->findOrFail($version);

                $snapshot->delete();
                $this->audit->record('version_deleted', $config, $request->user(), [
                    'deleted_version_id' => $version,
                ]);

                return response()->json([
                    'message' => 'Published website version deleted.',
                ]);
            });
        } catch (Throwable $exception) {
            $this->audit->failed('delete_version', $storeId, $request->user()->id, $exception, [
                'deleted_version_id' => $version,
            ]);

            throw $exception;
        }
    }

    private function configForAuthenticatedStore(): SiteConfig
    {
        return SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', (int) request()->user()->store_id)
            ->firstOrFail();
    }
}
