<?php

namespace App\Support\WebsiteEditor;

final class WebsiteDocumentSchema
{
    public const PAGE_MEDIA_FIELDS = [
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

    public const GLOBAL_APPEARANCE_FIELDS = [
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

    public const GLOBAL_DETAILS_FIELDS = [
        'site_name',
        'contact_email',
        'contact_phone',
        'contact_entries',
        'social_links',
    ];

    public const LOCALIZATION_FIELDS = [
        'default_locale',
        'supported_locales',
        'localized_content',
        'default_seo_title',
        'default_seo_description',
        'pages_seo',
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

    public const NULL_TO_BLANK_FIELDS = [
        'site_name',
        'contact_email',
        'contact_phone',
        'welcome_popup_heading',
        'welcome_popup_body',
    ];

    public const PUBLISH_AREAS = [
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

    /** @return array<int, string> */
    public static function draftFields(): array
    {
        return array_values(array_unique([
            ...self::FIELDS,
            ...self::GLOBAL_APPEARANCE_FIELDS,
            ...self::PAGE_MEDIA_FIELDS,
        ]));
    }
}
