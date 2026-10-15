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

class SiteConfigDraftController extends Controller
{
    public function __construct(
        private readonly WebsiteEditorAudit $audit,
        private readonly WebsiteEditorAssets $assets,
    ) {}

    public function show(): JsonResponse
    {
        $config = $this->configForAuthenticatedStore();
        $draft = SiteConfigDraft::query()->where('store_id', $config->store_id)->first();

        return response()->json(['data' => $this->responseData($config, $draft)]);
    }

    public function store(SaveSiteConfigDraftRequest $request): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => $this->persistDraft($request));
    }

    private function persistDraft(SaveSiteConfigDraftRequest $request): JsonResponse
    {
        $config = $this->configForAuthenticatedStore();
        $existing = SiteConfigDraft::query()->where('store_id', $config->store_id)->first();
        $submittedBase = $request->date('base_updated_at');
        $expectedBase = $existing?->base_updated_at ?? $config->updated_at;
        $submittedVersion = $request->integer('draft_version') ?: null;

        if (! $submittedBase || ! $expectedBase || ! $submittedBase->equalTo($expectedBase)) {
            return $this->conflictResponse();
        }

        if ($existing && $submittedVersion !== $existing->lock_version) {
            return $this->draftSaveConflictResponse();
        }

        if (! $existing && $submittedVersion !== null) {
            return $this->draftSaveConflictResponse();
        }

        $payload = $this->assets->normalizePayload($config, $request->validated('payload'));

        if ($existing) {
            $updated = SiteConfigDraft::query()
                ->whereKey($existing->id)
                ->where('lock_version', $submittedVersion)
                ->update([
                    'payload' => HomepageSnapshot::forDraft($payload),
                    'base_updated_at' => $expectedBase,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_by' => $request->user()->id,
                ]);

            if ($updated !== 1) {
                return $this->draftSaveConflictResponse();
            }

            $draft = SiteConfigDraft::query()->findOrFail($existing->id);
        } else {
            $draft = SiteConfigDraft::query()->create([
                'store_id' => $config->store_id,
                'payload' => HomepageSnapshot::forDraft($payload),
                'base_updated_at' => $expectedBase,
                'base_hash' => HomepageSnapshot::hash(HomepageSnapshot::fromConfig($config)),
                'lock_version' => 1,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
        }

        $this->assets->purgeUnusedDraftAssets($config, $draft->payload);

        $this->audit->record('draft_saved', $config, $request->user(), [
            'draft_id' => $draft->id,
            'draft_version' => $draft->lock_version,
        ]);

        return response()->json(['data' => $this->responseData($config, $draft)]);
    }

    public function destroy(): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => $this->discardDraft());
    }

    private function discardDraft(): JsonResponse
    {
        $config = $this->configForAuthenticatedStore();
        $draft = SiteConfigDraft::query()->where('store_id', $config->store_id)->first();

        if ($draft) {
            $draftId = $draft->id;
            $draftVersion = $draft->lock_version;
            $draft->delete();
            $this->audit->record('draft_discarded', $config, request()->user(), [
                'draft_id' => $draftId,
                'draft_version' => $draftVersion,
            ]);
        }

        $this->assets->purgeAllDraftAssets($config);

        return response()->json([
            'data' => $this->responseData($config, null),
            'message' => 'Draft discarded.',
        ]);
    }

    public function publish(): JsonResponse
    {
        $user = request()->user();
        $storeId = (int) $user->store_id;

        try {
            return DB::transaction(function () use ($storeId, $user): JsonResponse {
                $config = SiteConfig::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $draft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)
                    ->where('store_id', $storeId)
                    ->lockForUpdate()
                    ->first();

                if (! $draft) {
                    $this->audit->rejected('publish', 'draft_missing', $storeId, $user->id);

                    return response()->json(['message' => 'There is no saved draft to publish.'], 404);
                }

                $publishedPayload = HomepageSnapshot::fromConfig($config);

                $matchesPublishedSnapshot = collect(HomepageSnapshot::compatibleBaseHashes($publishedPayload))
                    ->contains(fn (string $hash): bool => hash_equals($draft->base_hash, $hash));

                if (! $matchesPublishedSnapshot) {
                    $this->audit->rejected('publish', 'draft_conflict', $storeId, $user->id, [
                        'draft_id' => $draft->id,
                    ]);

                    return $this->conflictResponse();
                }

                Validator::make(
                    ['payload' => $draft->payload],
                    SaveSiteConfigDraftRequest::payloadRules(),
                )->validate();

                $version = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->create([
                    'store_id' => $storeId,
                    'payload' => $publishedPayload,
                    'published_by' => $user->id,
                ]);
                $draftId = $draft->id;
                $draftVersion = $draft->lock_version;
                $config->update(HomepageSnapshot::forPersistence($draft->payload));
                $this->assets->publish($config, $draft->payload);
                $draft->delete();
                $this->audit->record('website_published', $config, $user, [
                    'draft_id' => $draftId,
                    'draft_version' => $draftVersion,
                    'archived_version_id' => $version->id,
                ]);

                return response()->json([
                    'data' => $this->responseData($config->fresh(), null),
                    'message' => 'Website published.',
                ]);
            });
        } catch (Throwable $exception) {
            $this->audit->failed('publish', $storeId, $user->id, $exception);

            throw $exception;
        }
    }

    public function uploadMedia(Request $request, string $collection): JsonResponse
    {
        abort_unless(in_array($collection, WebsiteEditorAssets::SLOTS, true), 404);

        $validated = $request->validate(['file' => WebsiteEditorAssets::uploadRules($collection)]);

        return response()->json(
            $this->assets->upload($this->configForAuthenticatedStore(), $validated['file'], $collection),
        );
    }

    private function configForAuthenticatedStore(): SiteConfig
    {
        $storeId = (int) request()->user()->store_id;

        return SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $storeId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function responseData(SiteConfig $config, ?SiteConfigDraft $draft): array
    {
        return [
            'payload' => $draft?->payload ?? HomepageSnapshot::fromConfig($config),
            'base_updated_at' => ($draft?->base_updated_at ?? $config->updated_at)?->toJSON(),
            'has_draft' => $draft !== null,
            'draft_updated_at' => $draft?->updated_at?->toJSON(),
            'draft_version' => $draft?->lock_version,
        ];
    }

    private function conflictResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The published website changed after this draft was opened. Refresh before continuing.',
            'code' => 'draft_conflict',
        ], 409);
    }

    private function draftSaveConflictResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This private draft was updated in another editor. Refresh before saving your changes.',
            'code' => 'draft_save_conflict',
        ], 409);
    }
}
