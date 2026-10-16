<?php

namespace App\Support\WebsiteEditor;

use App\Http\Requests\SaveSiteConfigDraftRequest;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\SiteConfigVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WebsiteDraftPublisher
{
    /** @var array<string, array<int, string>> */
    private const PUBLISH_AREA_FIELDS = [
        'Brand assets' => ['logo_media_id', 'logo_url', 'favicon_media_id', 'favicon_url', 'cart_icon_media_id', 'cart_icon_url', 'footer_logo_media_id', 'footer_logo_url', 'logo_alt_text', 'logo_size', 'footer_logo_size'],
        'Store details' => ['site_name', 'contact_email', 'contact_phone', 'contact_entries', 'social_links'],
        'Header' => ['header_cta'],
        'Announcement banner' => ['footer_banner'],
        'Footer' => ['footer'],
        'Home hero' => ['hero_media_id', 'hero_image_url', 'hero_media_mime', 'hero_title', 'hero_subtitle', 'hero_overlay_color', 'hero_overlay_opacity', 'hero_full_bleed', 'hero_focal_x', 'hero_focal_y'],
        'Home layout' => ['homepage_sections'],
        'How it works' => ['homepage_steps'],
        'Features' => ['homepage_features'],
        'Stats' => ['homepage_stats'],
        'Statement' => ['homepage_statement_media_id', 'homepage_statement_image_url', 'homepage_statement'],
        'Newsletter' => ['homepage_newsletter'],
        'About' => ['about_media_id', 'about_image_url', 'about_content', 'about_highlights'],
        'Story' => ['story_page', 'story_image_a_media_id', 'story_image_a_url', 'story_image_b_media_id', 'story_image_b_url'],
        'Contact' => ['contact_page', 'contact_media_id', 'contact_image_url'],
        'Shop' => ['shop_header', 'shop_promo'],
        'Blog' => ['blog_page', 'blog_media_id', 'blog_image_url'],
        'Services' => ['services_page', 'services_media_id', 'services_image_url'],
        'Welcome popup' => ['welcome_popup_enabled', 'welcome_popup_heading', 'welcome_popup_body'],
        'Languages' => ['default_locale', 'supported_locales', 'localized_content', 'default_seo_title', 'default_seo_description', 'pages_seo'],
    ];

    public function __construct(
        private readonly WebsiteEditorAudit $audit,
        private readonly WebsiteEditorAssets $assets,
        private readonly WebsiteLocalization $localization,
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
                SaveSiteConfigDraftRequest::payloadRules(),
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
            $config->update(HomepageSnapshot::forPersistence($draft->payload));
            $this->assets->publish($config, $draft->payload);
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
     * @param array<string, mixed> $published
     * @param array<string, mixed> $draft
     * @return array<int, string>
     */
    private function changedAreas(array $published, array $draft): array
    {
        return collect(self::PUBLISH_AREA_FIELDS)
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
