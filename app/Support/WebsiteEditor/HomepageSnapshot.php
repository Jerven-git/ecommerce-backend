<?php

namespace App\Support\WebsiteEditor;

use App\Models\SiteConfig;
use Illuminate\Support\Arr;

class HomepageSnapshot
{
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
        'hero_title',
        'hero_subtitle',
        'hero_overlay_color',
        'hero_overlay_opacity',
        'hero_full_bleed',
        'hero_focal_x',
        'hero_focal_y',
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
        'about_highlights',
        'story_page',
        'contact_page',
        'shop_header',
        'shop_promo',
        'blog_page',
        'services_page',
        'welcome_popup_enabled',
        'welcome_popup_heading',
        'welcome_popup_body',
    ];

    /** @return array<string, mixed> */
    public static function fromConfig(SiteConfig $config): array
    {
        $payload = Arr::only($config->toArray(), self::FIELDS);
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

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function forPersistence(array $payload): array
    {
        $payload = Arr::only($payload, self::FIELDS);

        foreach (['welcome_popup_heading', 'welcome_popup_body'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] === null) {
                $payload[$field] = '';
            }
        }

        return $payload;
    }
}
