<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSiteConfigDraftRequest;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\SiteConfigVersion;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteEditorAssets;
use App\Support\WebsiteEditor\WebsiteEditorAudit;
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
    ) {}

    public function index(): JsonResponse
    {
        $config = $this->configForAuthenticatedStore();
        $versions = SiteConfigVersion::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $config->store_id)
            ->latest('id')
            ->limit(25)
            ->get(['id', 'created_at']);

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
            ],
            'published_hash' => HomepageSnapshot::hash(HomepageSnapshot::fromConfig($config)),
        ]);
    }

    public function restore(Request $request, int $version): JsonResponse
    {
        $validated = $request->validate(['base_hash' => ['required', 'string', 'size:64']]);
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

                Validator::make(
                    ['payload' => $snapshot->payload],
                    SaveSiteConfigDraftRequest::payloadRules(),
                )->validate();

                $archived = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->create([
                    'store_id' => $storeId,
                    'payload' => $currentPayload,
                    'published_by' => $request->user()->id,
                ]);
                $config->update(HomepageSnapshot::forPersistence($snapshot->payload));
                $this->assets->publish($config, $snapshot->payload);
                SiteConfigDraft::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->delete();
                $this->assets->purgeAllDraftAssets($config);
                $this->audit->record('version_restored', $config, $request->user(), [
                    'restored_version_id' => $snapshot->id,
                    'archived_version_id' => $archived->id,
                ]);

                return response()->json([
                    'message' => 'Previous website version restored and published.',
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
