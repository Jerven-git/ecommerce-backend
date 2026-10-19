<?php

namespace App\Support\WebsiteEditor;

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\SiteConfigVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WebsiteDraftPublisher
{
    public function __construct(
        private readonly WebsiteEditorAudit $audit,
        private readonly WebsiteEditorAssets $assets,
        private readonly WebsiteLocalization $localization,
        private readonly WebsiteDocumentStore $documents,
    ) {}

    /** @return array{config: SiteConfig, version: SiteConfigVersion} */
    public function publish(int $storeId, User $user, ?string $releaseNote = null, bool $scheduled = false): array
    {
        return DB::transaction(function () use ($storeId, $user, $releaseNote, $scheduled): array {
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

                throw new WebsiteDraftPublishException('draft_missing', 404, 'There is no saved draft to publish.');
            }

            $publishedPayload = HomepageSnapshot::fromConfig($config);
            $matchesPublishedSnapshot = collect(HomepageSnapshot::compatibleBaseHashes($publishedPayload))
                ->contains(fn (string $hash): bool => hash_equals($draft->base_hash, $hash));

            if (! $matchesPublishedSnapshot) {
                $this->audit->rejected('publish', 'draft_conflict', $storeId, $user->id, [
                    'draft_id' => $draft->id,
                ]);

                throw new WebsiteDraftPublishException(
                    'draft_conflict',
                    409,
                    'The published website changed after this draft was opened. Refresh before continuing.',
                );
            }

            Validator::make(
                ['payload' => $draft->payload],
                WebsiteDocumentRules::all(),
            )->validate();
            $this->localization->validateDraftPayload($draft->payload);

            $version = SiteConfigVersion::withoutGlobalScope(StoreScope::class)->create([
                'store_id' => $storeId,
                'payload' => $publishedPayload,
                'published_by' => $user->id,
                'release_note' => $releaseNote,
                'changed_areas' => $this->changedAreas($publishedPayload, $draft->payload),
            ]);
            $draftId = $draft->id;
            $draftVersion = $draft->lock_version;
            $this->documents->write($config, $draft->payload);
            $config->update([
                'contact_email' => $draft->payload['contact_email'] ?? '',
                'contact_entries' => $draft->payload['contact_entries'] ?? [],
            ]);
            $this->assets->publish($config, $draft->payload);
            $config->touch();
            $draft->delete();
            $this->audit->record($scheduled ? 'website_scheduled_publish_completed' : 'website_published', $config, $user, [
                'draft_id' => $draftId,
                'draft_version' => $draftVersion,
                'archived_version_id' => $version->id,
                'has_release_note' => $releaseNote !== null,
                'changed_area_count' => count($version->changed_areas ?? []),
                'scheduled' => $scheduled,
            ]);

            return ['config' => $config->fresh(), 'version' => $version];
        });
    }

    /**
     * @param  array<string, mixed>  $published
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    private function changedAreas(array $published, array $draft): array
    {
        return collect(WebsiteDocumentSchema::PUBLISH_AREAS)
            ->filter(function (array $fields) use ($published, $draft): bool {
                foreach ($fields as $field) {
                    if (($published[$field] ?? null) !== ($draft[$field] ?? null)) {
                        return true;
                    }
                }

                return false;
            })
            ->keys()
            ->values()
            ->all();
    }
}
