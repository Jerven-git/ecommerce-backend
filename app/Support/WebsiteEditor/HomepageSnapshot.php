<?php

namespace App\Support\WebsiteEditor;

use App\Models\SiteConfig;
use Illuminate\Support\Arr;

class HomepageSnapshot
{
    private const PAGE_MEDIA_FIELDS = [
        'hero_media_id',
        'hero_image_url',
        'hero_media_mime',
        'homepage_statement_media_id',
        'homepage_statement_image_url',
        'about_media_id',
        'about_image_url',
        'story_image_a_media_id',
        'story_image_a_url',
        'story_image_b_media_id',
        'story_image_b_url',
        'contact_media_id',
        'contact_image_url',
        'blog_media_id',
        'blog_image_url',
        'services_media_id',
        'services_image_url',
    ];

    private const GLOBAL_APPEARANCE_FIELDS = [
        'logo_media_id',
        'logo_url',
        'favicon_media_id',
        'favicon_url',
        'cart_icon_media_id',
        'cart_icon_url',
        'footer_logo_media_id',
        'footer_logo_url',
        'logo_alt_text',
        'logo_size',
        'footer_logo_size',
    ];

    private const GLOBAL_DETAILS_FIELDS = [
        'site_name',
        'contact_email',
        'contact_phone',
        'contact_entries',
        'social_links',
    ];

    private const LOCALIZATION_FIELDS = [
        'default_locale',
        'supported_locales',
        'localized_content',
        'default_seo_title',
        'default_seo_description',
        'pages_seo',
    ];

    public const DEFAULT_SECTIONS = [
        ['id' => 'hero', 'visible' => true],
        ['id' => 'featured-products', 'visible' => true],
        ['id' => 'showcase', 'visible' => true],
        ['id' => 'watch-shop', 'visible' => true],
        ['id' => 'best-sellers', 'visible' => true],
        ['id' => 'how-it-works', 'visible' => true],
        ['id' => 'features', 'visible' => true],
        ['id' => 'stats', 'visible' => true],
        ['id' => 'statement', 'visible' => true],
        ['id' => 'newsletter', 'visible' => true],
    ];

    public const FIELDS = [
        'site_name',
        'default_locale',
        'supported_locales',
        'localized_content',
        'default_seo_title',
        'default_seo_description',
        'pages_seo',
        'contact_email',
        'contact_phone',
        'contact_entries',
        'social_links',
        'logo_alt_text',
        'logo_size',
        'footer_logo_size',
        'hero_title',
        'hero_subtitle',
        'hero_overlay_color',
        'hero_overlay_opacity',
        'hero_full_bleed',
        'hero_focal_x',
        'hero_focal_y',
        'hero_image_url',
        'hero_media_mime',
        'homepage_steps',
        'homepage_features',
        'homepage_stats',
        'homepage_statement',
        'homepage_newsletter',
        'homepage_sections',
        'header_cta',
        'footer_banner',
        'footer',
        'about_content',
        'about_image_url',
        'about_highlights',
        'story_page',
        'contact_page',
        'contact_image_url',
        'shop_header',
        'shop_promo',
        'blog_page',
        'blog_image_url',
        'services_page',
        'services_image_url',
        'welcome_popup_enabled',
        'welcome_popup_heading',
        'welcome_popup_body',
    ];

    /** @return array<string, mixed> */
    public static function fromConfig(SiteConfig $config): array
    {
        $payload = Arr::only($config->toArray(), self::FIELDS);
        $payload['site_name'] = $payload['site_name'] ?? '';
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
        $payload['default_seo_title'] = $payload['default_seo_title'] ?? null;
        $payload['default_seo_description'] = $payload['default_seo_description'] ?? null;
        $payload['pages_seo'] = ($payload['pages_seo'] ?? null) ?: [];
        $payload['contact_email'] = $payload['contact_email'] ?? '';
        $payload['contact_phone'] = $payload['contact_phone'] ?? '';
        $payload['contact_entries'] = ($payload['contact_entries'] ?? null) ?: [];
        $payload['social_links'] = ($payload['social_links'] ?? null) ?: [];
        foreach (WebsiteEditorAssets::SLOTS as $slot) {
            $media = $config->media()->where('collection', $slot)->first();
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
        $payload['logo_alt_text'] = $payload['logo_alt_text'] ?? '';
        $payload['logo_size'] = $payload['logo_size'] ?? 36;
        $payload['footer_logo_size'] = $payload['footer_logo_size'] ?? 128;
        $payload['hero_media_mime'] = ($payload['hero_media_mime'] ?? null)
            ?: $config->media()->where('collection', 'hero')->first()?->mime_type;
        $payload['hero_overlay_color'] = ($payload['hero_overlay_color'] ?? null) ?: '#000000';
        $payload['hero_overlay_opacity'] = $payload['hero_overlay_opacity'] ?? 45;
        $payload['hero_full_bleed'] = (bool) ($payload['hero_full_bleed'] ?? false);
        $payload['hero_focal_x'] = $payload['hero_focal_x'] ?? 50;
        $payload['hero_focal_y'] = $payload['hero_focal_y'] ?? 50;
        $payload['homepage_steps'] = ($payload['homepage_steps'] ?? null) ?: [
            'label' => '', 'heading' => '', 'subtitle' => '', 'items' => [],
        ];
        $payload['homepage_features'] = ($payload['homepage_features'] ?? null) ?: ['items' => []];
        $payload['homepage_stats'] = ($payload['homepage_stats'] ?? null) ?: ['items' => []];
        $payload['homepage_statement'] = ($payload['homepage_statement'] ?? null) ?: [
            'enabled' => false,
            'eyebrow' => '',
            'quote' => '',
            'attribution' => '',
            'role' => '',
            'cta_label' => '',
            'cta_link' => '',
        ];
        $payload['homepage_newsletter'] = ($payload['homepage_newsletter'] ?? null) ?: [
            'label' => '', 'heading' => '', 'subtitle' => '', 'disclaimer' => '',
        ];
        $payload['homepage_sections'] = ($payload['homepage_sections'] ?? null) ?: self::DEFAULT_SECTIONS;
        $payload['header_cta'] = ($payload['header_cta'] ?? null) ?: [
            'enabled' => false, 'label' => '', 'link' => '',
        ];
        $payload['footer_banner'] = ($payload['footer_banner'] ?? null) ?: [
            'enabled' => false,
            'heading' => '',
            'subtitle' => '',
            'button_label' => '',
            'button_link' => '',
            'background_color' => '#111827',
            'background_color_to' => '',
            'text_color' => '#ffffff',
        ];
        $payload['footer'] = ($payload['footer'] ?? null) ?: [
            'tagline' => '',
            'copyright_text' => '',
            'show_tagline' => true,
            'show_quick_links' => true,
            'show_contact_info' => true,
            'show_social_links' => true,
        ];
        $payload['about_content'] = $payload['about_content'] ?? '';
        $payload['about_highlights'] = ($payload['about_highlights'] ?? null) ?: ['items' => []];
        $payload['story_page'] = ($payload['story_page'] ?? null) ?: [
            'enabled' => false,
            'hero' => ['eyebrow' => '', 'heading' => '', 'subtitle' => ''],
            'section_a' => self::emptyStorySection('right', '/shop'),
            'section_b' => self::emptyStorySection('left', '/contact'),
        ];
        $payload['contact_page'] = ($payload['contact_page'] ?? null) ?: [
            'info_title' => '',
            'info_subtitle' => '',
            'info_description' => '',
            'info_badge' => '',
            'promises' => [],
            'faq_label' => '',
            'faq_heading' => '',
            'faq_subtitle' => '',
            'faqs' => [],
        ];
        $payload['shop_header'] = ($payload['shop_header'] ?? null) ?: [
            'label' => '', 'heading' => '', 'subtitle' => '',
        ];
        $payload['shop_promo'] = ($payload['shop_promo'] ?? null) ?: [
            'badge' => '', 'heading' => '', 'subtitle' => '', 'button_text' => '', 'perks' => [],
        ];
        $payload['blog_page'] = ($payload['blog_page'] ?? null) ?: [
            'header' => ['label' => '', 'heading' => '', 'subtitle' => ''],
            'cta' => null,
        ];
        $payload['services_page'] = ($payload['services_page'] ?? null) ?: [
            'header' => [
                'label' => '', 'heading' => '', 'subtitle' => '',
                'primary_cta' => null, 'secondary_cta' => null,
            ],
            'summary' => null,
            'stats' => null,
            'cta' => null,
        ];
        $payload['welcome_popup_enabled'] = (bool) ($payload['welcome_popup_enabled'] ?? false);
        $payload['welcome_popup_heading'] = $payload['welcome_popup_heading'] ?? '';
        $payload['welcome_popup_body'] = $payload['welcome_popup_body'] ?? '';

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function emptyStorySection(string $position, string $link): array
    {
        return [
            'heading' => '',
            'body' => '',
            'cta_label' => '',
            'cta_link' => $link,
            'image_position' => $position,
            'image_url' => null,
        ];
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
        return Arr::only($payload, [
            ...self::FIELDS,
            ...self::GLOBAL_APPEARANCE_FIELDS,
            ...self::PAGE_MEDIA_FIELDS,
        ]);
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

        foreach (['site_name', 'contact_email', 'contact_phone', 'welcome_popup_heading', 'welcome_popup_body'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] === null) {
                $payload[$field] = '';
            }
        }

        return $payload;
    }
}
