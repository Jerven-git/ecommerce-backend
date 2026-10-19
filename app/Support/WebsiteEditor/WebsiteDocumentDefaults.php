<?php

namespace App\Support\WebsiteEditor;

final class WebsiteDocumentDefaults
{
    public const HOMEPAGE_SECTIONS = [
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

    /** @return array<string, mixed> */
    public static function draft(): array
    {
        return array_replace(array_fill_keys(WebsiteDocumentSchema::FIELDS, null), [
            'site_name' => '',
            'default_locale' => WebsiteLocalization::DEFAULT_LOCALE,
            'supported_locales' => [WebsiteLocalization::DEFAULT_LOCALE],
            'localized_content' => [],
            'default_seo_title' => null,
            'default_seo_description' => null,
            'pages_seo' => [],
            'contact_email' => '',
            'contact_phone' => '',
            'contact_entries' => [],
            'social_links' => [],
            'logo_alt_text' => '',
            'logo_size' => 36,
            'footer_logo_size' => 128,
            'hero_title' => '',
            'hero_subtitle' => '',
            'hero_overlay_color' => '#000000',
            'hero_overlay_opacity' => 45,
            'hero_full_bleed' => false,
            'hero_focal_x' => 50,
            'hero_focal_y' => 50,
            'homepage_steps' => ['label' => '', 'heading' => '', 'subtitle' => '', 'items' => []],
            'homepage_features' => ['items' => []],
            'homepage_stats' => ['items' => []],
            'homepage_statement' => self::draftHomepageStatement(),
            'homepage_newsletter' => ['label' => '', 'heading' => '', 'subtitle' => '', 'disclaimer' => ''],
            'homepage_sections' => self::HOMEPAGE_SECTIONS,
            'header_cta' => self::headerCta(),
            'footer_banner' => self::footerBanner(),
            'footer' => self::draftFooter(),
            'about_content' => '',
            'about_highlights' => ['items' => []],
            'story_page' => self::draftStoryPage(),
            'contact_page' => [
                'info_title' => '',
                'info_subtitle' => '',
                'info_description' => '',
                'info_badge' => '',
                'promises' => [],
                'faq_label' => '',
                'faq_heading' => '',
                'faq_subtitle' => '',
                'faqs' => [],
            ],
            'shop_header' => ['label' => '', 'heading' => '', 'subtitle' => ''],
            'shop_promo' => ['badge' => '', 'heading' => '', 'subtitle' => '', 'button_text' => '', 'perks' => []],
            'blog_page' => [
                'header' => ['label' => '', 'heading' => '', 'subtitle' => ''],
                'cta' => null,
            ],
            'services_page' => [
                'header' => [
                    'label' => '',
                    'heading' => '',
                    'subtitle' => '',
                    'primary_cta' => null,
                    'secondary_cta' => null,
                ],
                'summary' => null,
                'stats' => null,
                'cta' => null,
            ],
            'welcome_popup_enabled' => false,
            'welcome_popup_heading' => '',
            'welcome_popup_body' => '',
        ]);
    }

    /** @return array<string, mixed> */
    public static function published(): array
    {
        return array_replace(self::draft(), [
            'site_name' => 'My Store',
            'hero_title' => 'Welcome to Our Store',
            'hero_subtitle' => 'Discover amazing products at great prices',
            'contact_email' => 'contact@store.com',
            'contact_phone' => '+1234567890',
            'homepage_statement' => self::publicHomepageStatement(),
            'story_page' => self::publicStoryPage(),
            'footer' => self::publicFooter(),
            'welcome_popup_heading' => 'Get 10% Off',
            'welcome_popup_body' => 'Sign up and get a discount code sent right to your inbox.',
        ]);
    }

    /** @return array{enabled: bool, label: string, link: string} */
    public static function headerCta(): array
    {
        return ['enabled' => false, 'label' => '', 'link' => ''];
    }

    /** @return array<string, mixed> */
    public static function footerBanner(): array
    {
        return [
            'enabled' => false,
            'heading' => '',
            'subtitle' => '',
            'button_label' => '',
            'button_link' => '',
            'background_color' => '#111827',
            'background_color_to' => '',
            'text_color' => '#ffffff',
        ];
    }

    /** @return array<string, mixed> */
    public static function draftFooter(): array
    {
        return [
            'tagline' => '',
            'copyright_text' => '',
            'show_tagline' => true,
            'show_quick_links' => true,
            'show_contact_info' => true,
            'show_social_links' => true,
        ];
    }

    /** @return array<string, mixed> */
    public static function publicFooter(): array
    {
        return [
            ...self::draftFooter(),
            'tagline' => 'Your trusted online shopping destination',
            'copyright_text' => '© {year} {site_name}. All rights reserved.',
        ];
    }

    /** @return array<string, mixed> */
    public static function draftHomepageStatement(): array
    {
        return [
            'enabled' => false,
            'eyebrow' => '',
            'quote' => '',
            'attribution' => '',
            'role' => '',
            'cta_label' => '',
            'cta_link' => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function publicHomepageStatement(): array
    {
        return [...self::draftHomepageStatement(), 'eyebrow' => 'Our Promise'];
    }

    /** @return array<string, mixed> */
    public static function draftStoryPage(): array
    {
        return [
            'enabled' => false,
            'hero' => ['eyebrow' => '', 'heading' => '', 'subtitle' => ''],
            'section_a' => self::storySection('right', '/shop'),
            'section_b' => self::storySection('left', '/contact'),
        ];
    }

    /** @return array<string, mixed> */
    public static function publicStoryPage(): array
    {
        $story = self::draftStoryPage();
        $story['hero']['eyebrow'] = 'Our Story';
        $story['hero']['heading'] = 'Our Story';
        $story['section_a']['heading'] = 'Who we are';
        $story['section_a']['cta_label'] = 'Shop the collection';
        $story['section_b']['heading'] = 'What we believe';
        $story['section_b']['cta_label'] = 'Get in touch';

        return $story;
    }

    /** @return array<string, mixed> */
    private static function storySection(string $position, string $link): array
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
}
