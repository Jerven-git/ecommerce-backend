<?php

namespace App\Support\WebsiteEditor;

use App\Models\SiteConfig;
use Illuminate\Support\Arr;

class HomepageSnapshot
{
    private const PAGE_MEDIA_FIELDS = WebsiteDocumentSchema::PAGE_MEDIA_FIELDS;

    private const GLOBAL_APPEARANCE_FIELDS = WebsiteDocumentSchema::GLOBAL_APPEARANCE_FIELDS;

    private const GLOBAL_DETAILS_FIELDS = WebsiteDocumentSchema::GLOBAL_DETAILS_FIELDS;

    private const LOCALIZATION_FIELDS = WebsiteDocumentSchema::LOCALIZATION_FIELDS;

    public const DEFAULT_SECTIONS = WebsiteDocumentDefaults::HOMEPAGE_SECTIONS;

    public const FIELDS = WebsiteDocumentSchema::FIELDS;

    /** @return array<string, mixed> */
    public static function fromConfig(SiteConfig $config): array
    {
        return app(WebsiteDocumentStore::class)->read($config);
    }

    /** @return array<string, mixed> */
    public static function fromLegacyConfig(SiteConfig $config): array
    {
        $defaults = WebsiteDocumentDefaults::published();
        $payload = array_replace($defaults, Arr::only($config->toArray(), self::FIELDS));
        $payload['site_name'] = $payload['site_name'] ?? $defaults['site_name'];
        $localization = app(WebsiteLocalization::class);
        $payload['default_locale'] = $localization->normalizeLocale($payload['default_locale'] ?? null)
            ?? WebsiteLocalization::DEFAULT_LOCALE;
        $payload['supported_locales'] = $localization->supportedLocales(
            $payload['supported_locales'] ?? [],
            $payload['default_locale'],
        );
        $payload['localized_content'] = $localization->sanitizeLocalizedContent(
            $payload['localized_content'] ?? [],
            $payload['supported_locales'],
            $payload['default_locale'],
        );
        $payload['default_seo_title'] = $payload['default_seo_title'] ?? $defaults['default_seo_title'];
        $payload['default_seo_description'] = $payload['default_seo_description'] ?? $defaults['default_seo_description'];
        $payload['pages_seo'] = ($payload['pages_seo'] ?? null) ?: $defaults['pages_seo'];
        $payload['contact_email'] = $payload['contact_email'] ?? $defaults['contact_email'];
        $payload['contact_phone'] = $payload['contact_phone'] ?? $defaults['contact_phone'];
        $payload['contact_entries'] = ($payload['contact_entries'] ?? null) ?: $defaults['contact_entries'];
        $payload['social_links'] = ($payload['social_links'] ?? null) ?: $defaults['social_links'];
        $mediaByCollection = $config->media()
            ->whereIn('collection', WebsiteEditorAssets::SLOTS)
            ->get()
            ->keyBy('collection');
        foreach (WebsiteEditorAssets::SLOTS as $slot) {
            $media = $mediaByCollection->get($slot);
            $payload[WebsiteEditorAssets::idField($slot)] = $media?->id;
            $payload[WebsiteEditorAssets::urlField($slot)] = match ($slot) {
                'hero' => $config->hero_image_url ?: $media?->url,
                'about' => $config->about_image_url ?: $media?->url,
                'contact' => $config->contact_image_url ?: $media?->url,
                'blog' => $config->blog_image_url ?: $media?->url,
                'services' => $config->services_image_url ?: $media?->url,
                default => $media?->url,
            };
        }
        $payload['logo_alt_text'] = $payload['logo_alt_text'] ?? $defaults['logo_alt_text'];
        $payload['logo_size'] = $payload['logo_size'] ?? $defaults['logo_size'];
        $payload['footer_logo_size'] = $payload['footer_logo_size'] ?? $defaults['footer_logo_size'];
        $payload['hero_media_mime'] = ($payload['hero_media_mime'] ?? null)
            ?: $mediaByCollection->get('hero')?->mime_type;
        $payload['hero_overlay_color'] = ($payload['hero_overlay_color'] ?? null) ?: $defaults['hero_overlay_color'];
        $payload['hero_overlay_opacity'] = $payload['hero_overlay_opacity'] ?? $defaults['hero_overlay_opacity'];
        $payload['hero_full_bleed'] = (bool) ($payload['hero_full_bleed'] ?? $defaults['hero_full_bleed']);
        $payload['hero_focal_x'] = $payload['hero_focal_x'] ?? $defaults['hero_focal_x'];
        $payload['hero_focal_y'] = $payload['hero_focal_y'] ?? $defaults['hero_focal_y'];

        foreach ([
            'homepage_steps',
            'homepage_features',
            'homepage_stats',
            'homepage_statement',
            'homepage_newsletter',
            'homepage_sections',
            'header_cta',
            'footer_banner',
            'footer',
            'about_highlights',
            'story_page',
            'contact_page',
            'shop_header',
            'shop_promo',
            'blog_page',
            'services_page',
        ] as $field) {
            $payload[$field] = ($payload[$field] ?? null) ?: $defaults[$field];
        }

        $payload['about_content'] = $payload['about_content'] ?? $defaults['about_content'];
        $payload['welcome_popup_enabled'] = (bool) ($payload['welcome_popup_enabled'] ?? $defaults['welcome_popup_enabled']);
        $payload['welcome_popup_heading'] = $payload['welcome_popup_heading'] ?? $defaults['welcome_popup_heading'];
        $payload['welcome_popup_body'] = $payload['welcome_popup_body'] ?? $defaults['welcome_popup_body'];

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    public static function hashBeforeGlobalDetails(array $payload): string
    {
        return self::hash(Arr::except($payload, self::GLOBAL_DETAILS_FIELDS));
    }

    /** @param array<string, mixed> $payload */
    public static function hashBeforeGlobalAppearance(array $payload): string
    {
        return self::hash(Arr::except($payload, self::GLOBAL_APPEARANCE_FIELDS));
    }

    /** @param array<string, mixed> $payload */
    public static function hashBeforeGlobalDetailsAndAppearance(array $payload): string
    {
        return self::hash(Arr::except($payload, [
            ...self::GLOBAL_DETAILS_FIELDS,
            ...self::GLOBAL_APPEARANCE_FIELDS,
        ]));
    }

    /** @param array<string, mixed> $payload */
    public static function hashBeforePageMedia(array $payload): string
    {
        return self::hash(Arr::except($payload, self::PAGE_MEDIA_FIELDS));
    }

    /** @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public static function compatibleBaseHashes(array $payload): array
    {
        $current = [
            self::hash($payload),
            self::hash(Arr::except($payload, self::GLOBAL_DETAILS_FIELDS)),
            self::hash(Arr::except($payload, self::GLOBAL_APPEARANCE_FIELDS)),
            self::hash(Arr::except($payload, [...self::GLOBAL_DETAILS_FIELDS, ...self::GLOBAL_APPEARANCE_FIELDS])),
            self::hash(Arr::except($payload, self::PAGE_MEDIA_FIELDS)),
            self::hash(Arr::except($payload, [...self::GLOBAL_DETAILS_FIELDS, ...self::PAGE_MEDIA_FIELDS])),
            self::hash(Arr::except($payload, [...self::GLOBAL_APPEARANCE_FIELDS, ...self::PAGE_MEDIA_FIELDS])),
            self::hash(Arr::except($payload, [
                ...self::GLOBAL_DETAILS_FIELDS,
                ...self::GLOBAL_APPEARANCE_FIELDS,
                ...self::PAGE_MEDIA_FIELDS,
            ])),
        ];
        $legacy = collect([
            [],
            self::GLOBAL_DETAILS_FIELDS,
            self::GLOBAL_APPEARANCE_FIELDS,
            [...self::GLOBAL_DETAILS_FIELDS, ...self::GLOBAL_APPEARANCE_FIELDS],
            self::PAGE_MEDIA_FIELDS,
            [...self::GLOBAL_DETAILS_FIELDS, ...self::PAGE_MEDIA_FIELDS],
            [...self::GLOBAL_APPEARANCE_FIELDS, ...self::PAGE_MEDIA_FIELDS],
            [...self::GLOBAL_DETAILS_FIELDS, ...self::GLOBAL_APPEARANCE_FIELDS, ...self::PAGE_MEDIA_FIELDS],
        ])->map(fn (array $fields): string => self::hash(Arr::except($payload, [
            ...$fields,
            ...self::LOCALIZATION_FIELDS,
        ])))->all();

        return array_values(array_unique([...$current, ...$legacy]));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function forDraft(array $payload): array
    {
        return Arr::only($payload, WebsiteDocumentSchema::draftFields());
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function forPersistence(array $payload): array
    {
        $payload = Arr::only($payload, self::FIELDS);
        $localization = app(WebsiteLocalization::class);
        $payload['default_locale'] = $localization->normalizeLocale($payload['default_locale'] ?? null)
            ?? WebsiteLocalization::DEFAULT_LOCALE;
        $payload['supported_locales'] = $localization->supportedLocales(
            $payload['supported_locales'] ?? [],
            $payload['default_locale'],
        );
        $payload['localized_content'] = $localization->sanitizeLocalizedContent(
            $payload['localized_content'] ?? [],
            $payload['supported_locales'],
            $payload['default_locale'],
        );

        foreach (WebsiteDocumentSchema::NULL_TO_BLANK_FIELDS as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] === null) {
                $payload[$field] = '';
            }
        }

        return $payload;
    }
}
