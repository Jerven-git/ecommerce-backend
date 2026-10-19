<?php

namespace App\Support\SiteConfig;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SiteConfig;
use App\Support\Tenancy\CurrentStore;
use App\Support\Tenancy\HostStoreResolver;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteDocumentDefaults;
use App\Support\WebsiteEditor\WebsiteLocalization;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class SiteConfigPresenter
{
    private const BEST_SELLERS_WINDOW_DAYS = 90;

    private const BEST_SELLERS_MIN_ORDERS = 3;

    private const BEST_SELLERS_MAX = 8;

    private const BEST_SELLERS_COUNTING_STATUSES = [
        'pending', 'processing', 'shipped', 'delivered',
        'backorder_awaiting_stock', 'backorder_notified',
    ];

    public function __construct(
        private readonly WebsiteLocalization $localization,
        private readonly CurrentStore $currentStore,
        private readonly HostStoreResolver $hostStoreResolver,
    ) {}

    /** @return array{data: array<string, mixed>, locale: string} */
    public function present(SiteConfig $config, Request $request): array
    {
        $document = HomepageSnapshot::fromConfig($config);
        $mediaByCollection = $config->storefrontMedia->keyBy('collection');
        $mediaById = $config->storefrontMedia->keyBy('id');
        $defaultLocale = $this->localization->normalizeLocale($document['default_locale'] ?? null)
            ?? WebsiteLocalization::DEFAULT_LOCALE;
        $supportedLocales = $this->localization->supportedLocales($document['supported_locales'] ?? [], $defaultLocale);
        $locale = $this->localization->resolveRequestLocaleFor($defaultLocale, $supportedLocales, $request);
        $localizedContent = $this->localization->sanitizeLocalizedContent(
            $document['localized_content'] ?? [],
            $supportedLocales,
            $defaultLocale,
        );

        $data = [
            'id' => $config->id,
            'is_storefront_host' => $this->currentStore->resolvedFromHost(),
            'canonical_host' => $this->hostStoreResolver->canonicalHostFor($request->getHost()),
            'site_name' => $document['site_name'],
            'default_locale' => $defaultLocale,
            'supported_locales' => $supportedLocales,
            'locale' => $locale,
            'theme' => $config->resolved_theme,
            'hero_title' => $document['hero_title'],
            'hero_subtitle' => $document['hero_subtitle'],
            'hero_overlay_color' => $document['hero_overlay_color'],
            'hero_overlay_opacity' => (int) $document['hero_overlay_opacity'],
            'hero_full_bleed' => (bool) $document['hero_full_bleed'],
            'hero_focal_x' => (int) $document['hero_focal_x'],
            'hero_focal_y' => (int) $document['hero_focal_y'],
            'about_content' => $document['about_content'],
            'story_page' => $this->storyPage($document['story_page'], $mediaByCollection),
            'about_overlay_color' => $config->about_overlay_color,
            'about_overlay_opacity' => (int) $config->about_overlay_opacity,
            'contact_overlay_color' => $config->contact_overlay_color,
            'contact_overlay_opacity' => (int) $config->contact_overlay_opacity,
            'badge_in_stock_color' => $config->badge_in_stock_color,
            'contact_email' => $document['contact_email'],
            'contact_phone' => $document['contact_phone'],
            'contact_entries' => $document['contact_entries'],
            'social_links' => $document['social_links'],
            'favorites_enabled' => (bool) $config->favorites_enabled,
            'show_stock_quantity' => (bool) $config->show_stock_quantity,
            'backorder_enabled' => (bool) $config->backorder_enabled,
            'welcome_popup_enabled' => (bool) $document['welcome_popup_enabled'],
            'welcome_popup_heading' => $document['welcome_popup_heading'],
            'welcome_popup_body' => $document['welcome_popup_body'],
            'welcome_popup_discount_id' => $config->welcome_popup_discount_id,
            'homepage_steps' => $document['homepage_steps'],
            'homepage_features' => $document['homepage_features'],
            'homepage_stats' => $document['homepage_stats'],
            'homepage_statement' => $this->homepageStatement($document['homepage_statement'], $mediaByCollection),
            'homepage_newsletter' => $document['homepage_newsletter'],
            'homepage_showcase' => $this->showcase($config, $mediaByCollection),
            'homepage_watch_shop' => $this->watchShop($config, $mediaByCollection, $mediaById),
            'homepage_best_sellers' => $this->bestSellers($config),
            'homepage_sections' => $document['homepage_sections'] ?: WebsiteDocumentDefaults::HOMEPAGE_SECTIONS,
            'about_highlights' => $document['about_highlights'],
            'shop_header' => $document['shop_header'],
            'shop_promo' => $document['shop_promo'],
            'contact_page' => $document['contact_page'],
            'blog_page' => $document['blog_page'],
            'blog_overlay_color' => $config->blog_overlay_color,
            'blog_overlay_opacity' => (int) $config->blog_overlay_opacity,
            'services_page' => $document['services_page'],
            'services_overlay_color' => $config->services_overlay_color,
            'services_overlay_opacity' => (int) $config->services_overlay_opacity,
            'modules_enabled' => SiteConfigModules::resolve($config->modules_enabled),
            'default_seo_title' => $document['default_seo_title'],
            'default_seo_description' => $document['default_seo_description'],
            'default_og_image_url' => $config->default_og_image_url,
            'pages_seo' => $document['pages_seo'],
            'canonical_base_url' => $config->canonical_base_url,
            'logo_alt_text' => $document['logo_alt_text'],
            'logo_size' => $document['logo_size'],
            'footer_logo_size' => $document['footer_logo_size'],
            'loader_animation' => $config->loader_animation ?: 'bounce',
            'header_cta' => $this->headerCta($document['header_cta']),
            'footer_banner' => $this->footerBanner($document['footer_banner']),
            'footer' => $this->footer($document['footer']),
            'currency_code' => $config->currency_code ?: 'USD',
            'updated_at' => $config->updated_at,
            'logo_url' => optional($mediaByCollection->get('logo'))->url,
            'favicon_url' => optional($mediaByCollection->get('favicon'))->url,
            'cart_icon_url' => optional($mediaByCollection->get('cart_icon'))->url,
            'footer_logo_url' => optional($mediaByCollection->get('footer_logo'))->url,
            'loader_logo_url' => optional($mediaByCollection->get('loader_logo'))->url,
            'hero_image_url' => $document['hero_image_url'] ?: optional($mediaByCollection->get('hero'))->url,
            'hero_media_mime' => $document['hero_media_mime'] ?: optional($mediaByCollection->get('hero'))->mime_type,
            'about_image_url' => $document['about_image_url'] ?: optional($mediaByCollection->get('about'))->url,
            'contact_image_url' => $document['contact_image_url'] ?: optional($mediaByCollection->get('contact'))->url,
            'blog_image_url' => $document['blog_image_url'] ?: optional($mediaByCollection->get('blog'))->url,
            'services_image_url' => $document['services_image_url'] ?: optional($mediaByCollection->get('services'))->url,
        ];

        if ($request->user()) {
            $data['localized_content'] = $localizedContent;
        }

        return [
            'data' => $this->localization->apply($data, $localizedContent, $locale),
            'locale' => $locale,
        ];
    }

    /** @return array<string, mixed> */
    private function showcase(SiteConfig $config, Collection $mediaByCollection): array
    {
        $stored = is_array($config->homepage_showcase) ? $config->homepage_showcase : [];
        $tiles = array_values($stored['tiles'] ?? []);
        $productIds = array_filter(array_map(
            fn ($tile) => isset($tile['featured_product_id']) ? (int) $tile['featured_product_id'] : null,
            $tiles,
        ));
        $productImages = $productIds
            ? Product::query()->whereIn('id', $productIds)->pluck('image_url', 'id')->all()
            : [];

        $resolvedTiles = [];
        for ($index = 0; $index < 4; $index++) {
            $tile = $tiles[$index] ?? [];
            $productId = isset($tile['featured_product_id']) ? (int) $tile['featured_product_id'] : null;
            $resolvedTiles[] = [
                'title' => (string) ($tile['title'] ?? ''),
                'cta_label' => (string) ($tile['cta_label'] ?? 'SHOP NOW'),
                'category_id' => isset($tile['category_id']) ? (int) $tile['category_id'] : null,
                'featured_product_id' => $productId,
                'image_url' => $productId !== null ? ($productImages[$productId] ?? null) : null,
            ];
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'label' => (string) ($stored['label'] ?? ''),
            'heading' => (string) ($stored['heading'] ?? ''),
            'subtitle' => (string) ($stored['subtitle'] ?? ''),
            'video_url' => optional($mediaByCollection->get('showcase_video'))->url,
            'video_poster_url' => optional($mediaByCollection->get('showcase_video_poster'))->url,
            'video_status' => (string) ($stored['video_status'] ?? 'idle'),
            'tiles' => $resolvedTiles,
        ];
    }

    /** @return array<string, mixed> */
    private function watchShop(
        SiteConfig $config,
        Collection $mediaByCollection,
        Collection $mediaById,
    ): array {
        $stored = is_array($config->homepage_watch_shop) ? $config->homepage_watch_shop : [];
        $cards = array_values($stored['cards'] ?? []);
        $productIds = array_filter(array_map(
            fn ($card) => isset($card['product_id']) ? (int) $card['product_id'] : null,
            $cards,
        ));
        $productsById = $productIds
            ? Product::query()->whereIn('id', $productIds)->get(['id', 'name', 'slug', 'image_url'])->keyBy('id')
            : collect();
        $resolved = [];
        foreach ($cards as $card) {
            $cardId = (string) ($card['id'] ?? '');
            $mediaId = isset($card['media_id']) ? (int) $card['media_id'] : null;
            $media = $mediaId !== null ? $mediaById->get($mediaId) : null;
            $productId = isset($card['product_id']) ? (int) $card['product_id'] : null;
            $product = $productId !== null ? $productsById->get($productId) : null;

            $resolved[] = [
                'id' => $cardId,
                'media_id' => $mediaId,
                'media_url' => $media?->url,
                'media_kind' => $media ? (str_starts_with((string) $media->mime_type, 'video/') ? 'video' : 'image') : null,
                'poster_url' => optional($mediaByCollection->get('watch_shop_poster:'.$cardId))->url,
                'media_status' => (string) ($media?->processing_status ?? 'ready'),
                'product' => $product ? [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'image_url' => $product->image_url,
                ] : null,
            ];
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'label' => (string) ($stored['label'] ?? ''),
            'heading' => (string) ($stored['heading'] ?? ''),
            'subtitle' => (string) ($stored['subtitle'] ?? ''),
            'cards' => $resolved,
        ];
    }

    /** @return array<string, mixed> */
    private function bestSellers(SiteConfig $config): array
    {
        $stored = is_array($config->homepage_best_sellers) ? $config->homepage_best_sellers : [];
        $enabled = (bool) ($stored['enabled'] ?? false);
        $fallbackIds = array_values(array_filter(array_map(
            fn ($id) => (int) $id,
            $stored['fallback_product_ids'] ?? [],
        )));
        $autoIds = $enabled
            ? OrderItem::query()
                ->select('product_id')
                ->selectRaw('SUM(quantity) as units_sold')
                ->selectRaw('COUNT(DISTINCT order_id) as order_count')
                ->whereNotNull('product_id')
                ->whereHas('order', function ($query) {
                    $query->whereIn('status', self::BEST_SELLERS_COUNTING_STATUSES)
                        ->where('created_at', '>=', now()->subDays(self::BEST_SELLERS_WINDOW_DAYS));
                })
                ->groupBy('product_id')
                ->orderByDesc('units_sold')
                ->limit(self::BEST_SELLERS_MAX)
                ->get()
            : collect();

        $hasSignal = $autoIds->sum('order_count') >= self::BEST_SELLERS_MIN_ORDERS;
        $ids = $hasSignal
            ? $autoIds->pluck('product_id')->all()
            : $fallbackIds;
        $products = empty($ids)
            ? collect()
            : Product::query()->whereIn('id', $ids)->where('is_active', true)->get();
        $byId = $products->keyBy('id');
        $ordered = [];
        foreach ($ids as $id) {
            if ($product = $byId->get($id)) {
                $ordered[] = $product;
            }
        }

        return [
            'enabled' => $enabled,
            'label' => (string) ($stored['label'] ?? ''),
            'heading' => (string) ($stored['heading'] ?? ''),
            'subtitle' => (string) ($stored['subtitle'] ?? ''),
            'source' => $hasSignal ? 'auto' : 'fallback',
            'fallback_product_ids' => $fallbackIds,
            'products' => array_map(fn ($product) => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'image_url' => $product->image_url,
                'price' => (string) $product->price,
                'stock' => (int) $product->stock,
                'allow_backorder' => (bool) $product->allow_backorder,
                'backorder_charge_policy' => $product->backorder_charge_policy,
                'can_backorder' => (bool) $product->allow_backorder,
                'is_active' => (bool) $product->is_active,
            ], $ordered),
        ];
    }

    /** @return array<string, mixed> */
    private function headerCta(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $defaults = WebsiteDocumentDefaults::headerCta();

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'label' => (string) ($stored['label'] ?? $defaults['label']),
            'link' => (string) ($stored['link'] ?? $defaults['link']),
        ];
    }

    /** @return array<string, mixed> */
    private function footer(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $defaults = WebsiteDocumentDefaults::publicFooter();

        return [
            'tagline' => (string) ($stored['tagline'] ?? $defaults['tagline']),
            'copyright_text' => (string) ($stored['copyright_text'] ?? $defaults['copyright_text']),
            'show_tagline' => (bool) ($stored['show_tagline'] ?? $defaults['show_tagline']),
            'show_quick_links' => (bool) ($stored['show_quick_links'] ?? $defaults['show_quick_links']),
            'show_contact_info' => (bool) ($stored['show_contact_info'] ?? $defaults['show_contact_info']),
            'show_social_links' => (bool) ($stored['show_social_links'] ?? $defaults['show_social_links']),
        ];
    }

    /** @return array<string, mixed> */
    private function footerBanner(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $defaults = WebsiteDocumentDefaults::footerBanner();

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'heading' => (string) ($stored['heading'] ?? $defaults['heading']),
            'subtitle' => (string) ($stored['subtitle'] ?? $defaults['subtitle']),
            'button_label' => (string) ($stored['button_label'] ?? $defaults['button_label']),
            'button_link' => (string) ($stored['button_link'] ?? $defaults['button_link']),
            'background_color' => (string) ($stored['background_color'] ?? $defaults['background_color']),
            'background_color_to' => (string) ($stored['background_color_to'] ?? $defaults['background_color_to']),
            'text_color' => (string) ($stored['text_color'] ?? $defaults['text_color']),
        ];
    }

    /** @return array<string, mixed> */
    private function homepageStatement(mixed $stored, Collection $mediaByCollection): array
    {
        $stored = is_array($stored) ? $stored : [];
        $defaults = WebsiteDocumentDefaults::publicHomepageStatement();

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'eyebrow' => (string) ($stored['eyebrow'] ?? $defaults['eyebrow']),
            'quote' => (string) ($stored['quote'] ?? $defaults['quote']),
            'attribution' => (string) ($stored['attribution'] ?? $defaults['attribution']),
            'role' => (string) ($stored['role'] ?? $defaults['role']),
            'cta_label' => (string) ($stored['cta_label'] ?? $defaults['cta_label']),
            'cta_link' => (string) ($stored['cta_link'] ?? $defaults['cta_link']),
            'image_url' => optional($mediaByCollection->get('homepage_statement'))->url,
        ];
    }

    /** @return array<string, mixed> */
    private function storyPage(mixed $stored, Collection $mediaByCollection): array
    {
        $stored = is_array($stored) ? $stored : [];
        $hero = is_array($stored['hero'] ?? null) ? $stored['hero'] : [];
        $sectionA = is_array($stored['section_a'] ?? null) ? $stored['section_a'] : [];
        $sectionB = is_array($stored['section_b'] ?? null) ? $stored['section_b'] : [];
        $defaults = WebsiteDocumentDefaults::publicStoryPage();

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'hero' => [
                'eyebrow' => (string) ($hero['eyebrow'] ?? $defaults['hero']['eyebrow']),
                'heading' => (string) ($hero['heading'] ?? $defaults['hero']['heading']),
                'subtitle' => (string) ($hero['subtitle'] ?? $defaults['hero']['subtitle']),
            ],
            'section_a' => [
                'heading' => (string) ($sectionA['heading'] ?? $defaults['section_a']['heading']),
                'body' => (string) ($sectionA['body'] ?? $defaults['section_a']['body']),
                'cta_label' => (string) ($sectionA['cta_label'] ?? $defaults['section_a']['cta_label']),
                'cta_link' => (string) ($sectionA['cta_link'] ?? $defaults['section_a']['cta_link']),
                'image_position' => ($sectionA['image_position'] ?? $defaults['section_a']['image_position']) === 'left' ? 'left' : 'right',
                'image_url' => optional($mediaByCollection->get('story_image_a'))->url,
            ],
            'section_b' => [
                'heading' => (string) ($sectionB['heading'] ?? $defaults['section_b']['heading']),
                'body' => (string) ($sectionB['body'] ?? $defaults['section_b']['body']),
                'cta_label' => (string) ($sectionB['cta_label'] ?? $defaults['section_b']['cta_label']),
                'cta_link' => (string) ($sectionB['cta_link'] ?? $defaults['section_b']['cta_link']),
                'image_position' => ($sectionB['image_position'] ?? $defaults['section_b']['image_position']) === 'right' ? 'right' : 'left',
                'image_url' => optional($mediaByCollection->get('story_image_b'))->url,
            ],
        ];
    }
}
