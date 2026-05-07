<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\OptimizeShowcaseVideoJob;
use App\Jobs\OptimizeWatchShopMediaJob;
use App\Models\Media;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SiteConfig;
use App\Modules\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteConfigController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    private const MODULE_KEYS = ['shop', 'blog', 'services', 'about', 'contact'];

    /**
     * Build the showcase block returned to clients. Always returns a stable
     * shape (4 tiles, video URL+poster from media, resolved tile image URLs)
     * so the homepage can render without a second request.
     *
     * @return array{enabled: bool, label: string, heading: string, subtitle: string, video_url: string|null, video_poster_url: string|null, video_status: string, tiles: array<int, array{title: string, cta_label: string, category_id: int|null, featured_product_id: int|null, image_url: string|null}>}
     */
    private function resolveShowcase(SiteConfig $config): array
    {
        $stored = is_array($config->homepage_showcase) ? $config->homepage_showcase : [];
        $tiles = array_values($stored['tiles'] ?? []);

        $productIds = array_filter(array_map(
            fn ($t) => isset($t['featured_product_id']) ? (int) $t['featured_product_id'] : null,
            $tiles,
        ));
        $productImages = $productIds
            ? Product::query()->whereIn('id', $productIds)->pluck('image_url', 'id')->all()
            : [];

        $resolvedTiles = [];
        for ($i = 0; $i < 4; $i++) {
            $tile = $tiles[$i] ?? [];
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
            'video_url' => optional($config->showcaseVideoMedia)->url,
            'video_poster_url' => optional($config->showcaseVideoPosterMedia)->url,
            'video_status' => (string) ($stored['video_status'] ?? 'idle'),
            'tiles' => $resolvedTiles,
        ];
    }

    /**
     * Build the Watch & Shop block returned to clients. Each stored card is
     * resolved into the full shape the frontend needs: the card's media URL,
     * its poster URL (looked up from a sibling `watch_shop_poster:{cardId}`
     * Media row), its kind (video|image), and the linked product's name + slug
     * + thumbnail. This keeps the homepage to a single API request.
     *
     * @return array{enabled: bool, label: string, heading: string, subtitle: string, cards: array<int, array{id: string, media_id: int|null, media_url: string|null, media_kind: string|null, poster_url: string|null, media_status: string, product: array{id: int, name: string, slug: string, image_url: string|null}|null}>}
     */
    private function resolveWatchShop(SiteConfig $config): array
    {
        $stored = is_array($config->homepage_watch_shop) ? $config->homepage_watch_shop : [];
        $cards = array_values($stored['cards'] ?? []);

        $mediaIds = array_filter(array_map(
            fn ($c) => isset($c['media_id']) ? (int) $c['media_id'] : null,
            $cards,
        ));
        $productIds = array_filter(array_map(
            fn ($c) => isset($c['product_id']) ? (int) $c['product_id'] : null,
            $cards,
        ));

        $mediaById = $mediaIds
            ? Media::query()->whereIn('id', $mediaIds)->get()->keyBy('id')
            : collect();
        $productsById = $productIds
            ? Product::query()->whereIn('id', $productIds)->get(['id', 'name', 'slug', 'image_url'])->keyBy('id')
            : collect();

        // Build a `card_id => poster_url` map in one query rather than per-card.
        $cardIds = array_filter(array_map(fn ($c) => $c['id'] ?? null, $cards));
        $posterCollections = array_map(fn ($id) => 'watch_shop_poster:'.$id, $cardIds);
        $postersByCollection = $posterCollections
            ? $config->media()->whereIn('collection', $posterCollections)->get()->keyBy('collection')
            : collect();

        $resolved = [];
        foreach ($cards as $card) {
            $cardId = (string) ($card['id'] ?? '');
            $mediaId = isset($card['media_id']) ? (int) $card['media_id'] : null;
            $media = $mediaId !== null ? $mediaById->get($mediaId) : null;

            $kind = null;
            if ($media) {
                $kind = str_starts_with((string) $media->mime_type, 'video/') ? 'video' : 'image';
            }

            $productId = isset($card['product_id']) ? (int) $card['product_id'] : null;
            $product = $productId !== null ? $productsById->get($productId) : null;

            $resolved[] = [
                'id' => $cardId,
                'media_id' => $mediaId,
                'media_url' => $media?->url,
                'media_kind' => $kind,
                'poster_url' => optional($postersByCollection->get('watch_shop_poster:'.$cardId))->url,
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

    /**
     * Window over which we count product sales when computing "best sellers".
     */
    private const BEST_SELLERS_WINDOW_DAYS = 90;

    /**
     * Minimum number of distinct order rows needed for the auto query to be
     * considered meaningful. Below this, we fall back to the admin's curated
     * list — a single fluke order shouldn't decide what's promoted on the
     * homepage.
     */
    private const BEST_SELLERS_MIN_ORDERS = 3;

    /**
     * How many products the section will show at most.
     */
    private const BEST_SELLERS_MAX = 8;

    /**
     * Order statuses that count toward best-seller rankings. Cancelled and
     * expired backorders represent products that didn't actually ship/sell.
     */
    private const BEST_SELLERS_COUNTING_STATUSES = [
        'pending', 'processing', 'shipped', 'delivered',
        'backorder_awaiting_stock', 'backorder_notified',
    ];

    /**
     * Build the Best Sellers block. Auto-queries the top N products by units
     * sold over the last 90 days; if the signal is too weak (fewer than
     * BEST_SELLERS_MIN_ORDERS distinct orders) the admin's curated fallback
     * list is used instead. Returns the resolved Product rows so the homepage
     * can render in one request.
     *
     * @return array{enabled: bool, label: string, heading: string, subtitle: string, source: string, products: array<int, array{id: int, name: string, slug: string, image_url: string|null, price: string, stock: int, allow_backorder: bool, backorder_charge_policy: string|null, can_backorder: bool, is_active: bool}>}
     */
    private function resolveBestSellers(SiteConfig $config): array
    {
        $stored = is_array($config->homepage_best_sellers) ? $config->homepage_best_sellers : [];

        $autoIds = OrderItem::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity) as units_sold')
            ->selectRaw('COUNT(DISTINCT order_id) as order_count')
            ->whereNotNull('product_id')
            ->whereHas('order', function ($q) {
                $q->whereIn('status', self::BEST_SELLERS_COUNTING_STATUSES)
                    ->where('created_at', '>=', now()->subDays(self::BEST_SELLERS_WINDOW_DAYS));
            })
            ->groupBy('product_id')
            ->orderByDesc('units_sold')
            ->limit(self::BEST_SELLERS_MAX)
            ->get();

        $hasSignal = $autoIds->sum('order_count') >= self::BEST_SELLERS_MIN_ORDERS;
        $source = $hasSignal ? 'auto' : 'fallback';

        $ids = $hasSignal
            ? $autoIds->pluck('product_id')->all()
            : array_values(array_filter(array_map(
                fn ($id) => (int) $id,
                $stored['fallback_product_ids'] ?? [],
            )));

        $products = empty($ids)
            ? collect()
            : Product::query()
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->get();

        // Re-order to match the ranking we computed (DB whereIn doesn't preserve
        // order). Auto: highest units first; fallback: admin's chosen order.
        $byId = $products->keyBy('id');
        $ordered = [];
        foreach ($ids as $id) {
            if ($product = $byId->get($id)) {
                $ordered[] = $product;
            }
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'label' => (string) ($stored['label'] ?? ''),
            'heading' => (string) ($stored['heading'] ?? ''),
            'subtitle' => (string) ($stored['subtitle'] ?? ''),
            'source' => $source,
            // Echo the admin's stored fallback list so the settings form can
            // round-trip its selection. Public consumers ignore this field.
            'fallback_product_ids' => array_values(array_filter(array_map(
                fn ($id) => (int) $id,
                $stored['fallback_product_ids'] ?? [],
            ))),
            'products' => array_map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'image_url' => $p->image_url,
                'price' => (string) $p->price,
                'stock' => (int) $p->stock,
                'allow_backorder' => (bool) $p->allow_backorder,
                'backorder_charge_policy' => $p->backorder_charge_policy,
                'can_backorder' => (bool) $p->allow_backorder,
                'is_active' => (bool) $p->is_active,
            ], $ordered),
        ];
    }

    /**
     * Cross-field validation for the showcase block. Runs after Laravel's
     * basic shape rules. Mirrors the admin form's save-time gate so the same
     * rules apply whether the client is the dashboard or any future API caller.
     *
     * @param  array<string, mixed>  $validated
     */
    private function validateShowcaseRules(array $validated): void
    {
        if (! isset($validated['homepage_showcase'])) {
            return;
        }

        $showcase = $validated['homepage_showcase'];
        $enabled = (bool) ($showcase['enabled'] ?? false);
        $tiles = $showcase['tiles'] ?? [];
        $errors = [];

        // Heading required when the section is visible.
        if ($enabled && trim((string) ($showcase['heading'] ?? '')) === '') {
            $errors['homepage_showcase.heading'] = ['A heading is required when the showcase is visible.'];
        }

        // Each tile is either fully empty or fully wired (category + product).
        foreach ($tiles as $i => $tile) {
            $hasTitle = ! empty($tile['title']);
            $hasCategory = ! empty($tile['category_id']);
            $hasProduct = ! empty($tile['featured_product_id']);
            $touched = $hasTitle || $hasCategory || $hasProduct;

            if ($touched && ! $hasCategory) {
                $errors["homepage_showcase.tiles.$i.category_id"] = ['Tile must have a category.'];
            }
            if ($touched && ! $hasProduct) {
                $errors["homepage_showcase.tiles.$i.featured_product_id"] = ['Tile must have a product.'];
            }
        }

        // When visible, at least one tile has to actually render.
        if ($enabled) {
            $anyComplete = collect($tiles)->contains(
                fn ($t) => ! empty($t['category_id']) && ! empty($t['featured_product_id'])
            );
            if (! $anyComplete) {
                $errors['homepage_showcase.tiles'] = ['Showcase needs at least one tile with a category and product.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Cross-field validation for Watch & Shop. Same shape as the showcase
     * helper above — heading required when visible, every card needs a
     * product, and at least one card has to exist when visible.
     *
     * @param  array<string, mixed>  $validated
     */
    private function validateWatchShopRules(array $validated): void
    {
        if (! isset($validated['homepage_watch_shop'])) {
            return;
        }

        $watchShop = $validated['homepage_watch_shop'];
        $enabled = (bool) ($watchShop['enabled'] ?? false);
        $cards = $watchShop['cards'] ?? [];
        $errors = [];

        if ($enabled && trim((string) ($watchShop['heading'] ?? '')) === '') {
            $errors['homepage_watch_shop.heading'] = ['A heading is required when Watch & Shop is visible.'];
        }

        foreach ($cards as $i => $card) {
            if (empty($card['product_id'])) {
                $errors["homepage_watch_shop.cards.$i.product_id"] = ['Each card must be linked to a product.'];
            }
        }

        if ($enabled && empty($cards)) {
            $errors['homepage_watch_shop.cards'] = ['Watch & Shop needs at least one card when visible.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Cross-field validation for the Best Sellers block. The fallback list is
     * what the section displays when there isn't enough order history yet, so
     * if the section is enabled AND the auto query has no signal, an empty
     * fallback would render an empty section. We can't know "is there signal?"
     * from form state alone — but we can require a heading and bound the list
     * size (the front-end and back-end both cap to BEST_SELLERS_MAX).
     *
     * @param  array<string, mixed>  $validated
     */
    private function validateBestSellersRules(array $validated): void
    {
        if (! isset($validated['homepage_best_sellers'])) {
            return;
        }

        $bestSellers = $validated['homepage_best_sellers'];
        $enabled = (bool) ($bestSellers['enabled'] ?? false);
        $errors = [];

        if ($enabled && trim((string) ($bestSellers['heading'] ?? '')) === '') {
            $errors['homepage_best_sellers.heading'] = ['A heading is required when Best Sellers is visible.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Merge stored module flags over the default (all enabled). Keeps the API
     * response shape stable even if the stored JSON is null or missing keys.
     */
    private function resolveModulesEnabled(?array $stored): array
    {
        $defaults = array_fill_keys(self::MODULE_KEYS, true);
        $stored = is_array($stored) ? $stored : [];

        $out = [];
        foreach (self::MODULE_KEYS as $key) {
            $out[$key] = (bool) ($stored[$key] ?? $defaults[$key]);
        }

        return $out;
    }

    private function config(): SiteConfig
    {
        return SiteConfig::with([
            'logoMedia', 'faviconMedia', 'cartIconMedia', 'heroMedia',
            'aboutMedia', 'contactMedia', 'blogMedia', 'servicesMedia',
            'showcaseVideoMedia', 'showcaseVideoPosterMedia',
        ])->first() ?? SiteConfig::create([]);
    }

    public function show()
    {
        $config = $this->config();

        return response()->json([
            'data' => [
                'id' => $config->id,
                'site_name' => $config->site_name,
                'theme' => $config->resolved_theme,
                'hero_title' => $config->hero_title,
                'hero_subtitle' => $config->hero_subtitle,
                'hero_overlay_color' => $config->hero_overlay_color,
                'hero_overlay_opacity' => (int) $config->hero_overlay_opacity,
                'hero_full_bleed' => (bool) $config->hero_full_bleed,
                'hero_focal_x' => (int) $config->hero_focal_x,
                'hero_focal_y' => (int) $config->hero_focal_y,
                'about_content' => $config->about_content,
                'about_overlay_color' => $config->about_overlay_color,
                'about_overlay_opacity' => (int) $config->about_overlay_opacity,
                'contact_overlay_color' => $config->contact_overlay_color,
                'contact_overlay_opacity' => (int) $config->contact_overlay_opacity,
                'badge_in_stock_color' => $config->badge_in_stock_color,
                'contact_email' => $config->contact_email,
                'contact_phone' => $config->contact_phone,
                'contact_entries' => $config->contact_entries ?? [],
                'social_links' => $config->social_links ?? [],
                'favorites_enabled' => (bool) $config->favorites_enabled,
                'show_stock_quantity' => (bool) $config->show_stock_quantity,
                'backorder_enabled' => (bool) $config->backorder_enabled,
                'welcome_popup_enabled' => (bool) $config->welcome_popup_enabled,
                'welcome_popup_heading' => $config->welcome_popup_heading,
                'welcome_popup_body' => $config->welcome_popup_body,
                'welcome_popup_discount_id' => $config->welcome_popup_discount_id,
                'homepage_steps' => $config->homepage_steps,
                'homepage_features' => $config->homepage_features,
                'homepage_stats' => $config->homepage_stats,
                'homepage_newsletter' => $config->homepage_newsletter,
                'homepage_showcase' => $this->resolveShowcase($config),
                'homepage_watch_shop' => $this->resolveWatchShop($config),
                'homepage_best_sellers' => $this->resolveBestSellers($config),
                'about_highlights' => $config->about_highlights,
                'shop_header' => $config->shop_header,
                'shop_promo' => $config->shop_promo,
                'contact_page' => $config->contact_page,
                'blog_page' => $config->blog_page,
                'blog_overlay_color' => $config->blog_overlay_color,
                'blog_overlay_opacity' => (int) $config->blog_overlay_opacity,
                'services_page' => $config->services_page,
                'services_overlay_color' => $config->services_overlay_color,
                'services_overlay_opacity' => (int) $config->services_overlay_opacity,
                'modules_enabled' => $this->resolveModulesEnabled($config->modules_enabled),
                'default_seo_title' => $config->default_seo_title,
                'default_seo_description' => $config->default_seo_description,
                'default_og_image_url' => $config->default_og_image_url,
                'pages_seo' => $config->pages_seo ?? [],
                'canonical_base_url' => $config->canonical_base_url,
                'logo_alt_text' => $config->logo_alt_text,
                'updated_at' => $config->updated_at,

                // urls come from media
                'logo_url' => optional($config->logoMedia)->url,
                'favicon_url' => optional($config->faviconMedia)->url,
                'cart_icon_url' => optional($config->cartIconMedia)->url,
                'hero_image_url' => $config->hero_image_url ?: optional($config->heroMedia)->url,
                'hero_media_mime' => $config->hero_media_mime ?: optional($config->heroMedia)->mime_type,
                'about_image_url' => optional($config->aboutMedia)->url,
                'contact_image_url' => optional($config->contactMedia)->url,
                'blog_image_url' => optional($config->blogMedia)->url,
                'services_image_url' => optional($config->servicesMedia)->url,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'site_name' => 'nullable|string|max:255',
            'theme' => 'nullable|array',
            'theme.primary_color' => 'nullable|string|max:7',
            'theme.secondary_color' => 'nullable|string|max:7',
            'theme.accent_color' => 'nullable|string|max:7',
            'theme.heading_font' => 'nullable|string|max:100',
            'theme.body_font' => 'nullable|string|max:100',
            'theme.texture' => 'nullable|string|max:50',
            'hero_title' => 'nullable|string|max:255',
            'hero_subtitle' => 'nullable|string|max:255',
            'hero_overlay_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'hero_overlay_opacity' => 'nullable|integer|min:0|max:100',
            'hero_full_bleed' => 'nullable|boolean',
            'hero_focal_x' => 'nullable|integer|min:0|max:100',
            'hero_focal_y' => 'nullable|integer|min:0|max:100',
            'hero_image_url' => 'nullable|string|max:500',
            'hero_media_mime' => 'nullable|string|max:100',
            'about_content' => 'nullable|string',
            'about_overlay_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'about_overlay_opacity' => 'nullable|integer|min:0|max:100',
            'contact_overlay_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'contact_overlay_opacity' => 'nullable|integer|min:0|max:100',
            'badge_in_stock_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:20',
            'contact_entries' => 'nullable|array|max:20',
            'contact_entries.*.label' => 'required|string|max:100',
            'contact_entries.*.email' => 'nullable|email|max:255',
            'contact_entries.*.phone' => 'nullable|string|max:30',
            'social_links' => 'nullable|array|max:20',
            'social_links.*.platform' => 'required|string|max:50',
            'social_links.*.url' => 'required|url|max:500',
            'social_links.*.label' => 'nullable|string|max:100',
            'favorites_enabled' => 'nullable|boolean',
            'show_stock_quantity' => 'nullable|boolean',
            'welcome_popup_enabled' => 'nullable|boolean',
            'welcome_popup_heading' => 'nullable|string|max:255',
            'welcome_popup_body' => 'nullable|string|max:1000',
            'welcome_popup_discount_id' => 'nullable|integer|exists:discounts,id',
            'homepage_steps' => 'nullable|array',
            'homepage_steps.label' => 'nullable|string|max:100',
            'homepage_steps.heading' => 'nullable|string|max:100',
            'homepage_steps.subtitle' => 'nullable|string|max:255',
            'homepage_steps.items' => 'nullable|array|max:12',
            'homepage_steps.items.*.icon' => 'nullable|string|max:100',
            'homepage_steps.items.*.title' => 'required|string|max:100',
            'homepage_steps.items.*.description' => 'required|string|max:255',
            'homepage_features' => 'nullable|array',
            'homepage_features.items' => 'nullable|array|max:12',
            'homepage_features.items.*.icon' => 'nullable|string|max:100',
            'homepage_features.items.*.title' => 'required|string|max:100',
            'homepage_features.items.*.description' => 'required|string|max:255',
            'homepage_stats' => 'nullable|array',
            'homepage_stats.items' => 'nullable|array|max:8',
            'homepage_stats.items.*.value' => 'required|string|max:50',
            'homepage_stats.items.*.label' => 'required|string|max:100',
            'homepage_newsletter' => 'nullable|array',
            'homepage_newsletter.label' => 'nullable|string|max:100',
            'homepage_newsletter.heading' => 'nullable|string|max:100',
            'homepage_newsletter.subtitle' => 'nullable|string|max:255',
            'homepage_newsletter.disclaimer' => 'nullable|string|max:255',
            'homepage_showcase' => 'nullable|array',
            'homepage_showcase.enabled' => 'nullable|boolean',
            'homepage_showcase.label' => 'nullable|string|max:100',
            'homepage_showcase.heading' => 'nullable|string|max:150',
            'homepage_showcase.subtitle' => 'nullable|string|max:255',
            'homepage_showcase.tiles' => 'nullable|array|size:4',
            'homepage_showcase.tiles.*.title' => 'nullable|string|max:100',
            'homepage_showcase.tiles.*.cta_label' => 'nullable|string|max:30',
            'homepage_showcase.tiles.*.category_id' => 'nullable|integer|exists:categories,id',
            'homepage_showcase.tiles.*.featured_product_id' => 'nullable|integer|exists:products,id',
            'homepage_watch_shop' => 'nullable|array',
            'homepage_watch_shop.enabled' => 'nullable|boolean',
            'homepage_watch_shop.label' => 'nullable|string|max:100',
            'homepage_watch_shop.heading' => 'nullable|string|max:150',
            'homepage_watch_shop.subtitle' => 'nullable|string|max:255',
            'homepage_watch_shop.cards' => 'nullable|array|max:12',
            'homepage_watch_shop.cards.*.id' => 'required|string|max:64',
            'homepage_watch_shop.cards.*.media_id' => 'nullable|integer|exists:media,id',
            'homepage_watch_shop.cards.*.product_id' => 'nullable|integer|exists:products,id',
            'homepage_best_sellers' => 'nullable|array',
            'homepage_best_sellers.enabled' => 'nullable|boolean',
            'homepage_best_sellers.label' => 'nullable|string|max:100',
            'homepage_best_sellers.heading' => 'nullable|string|max:150',
            'homepage_best_sellers.subtitle' => 'nullable|string|max:255',
            'homepage_best_sellers.fallback_product_ids' => 'nullable|array|max:'.self::BEST_SELLERS_MAX,
            'homepage_best_sellers.fallback_product_ids.*' => 'integer|exists:products,id',
            'about_highlights' => 'nullable|array',
            'about_highlights.items' => 'nullable|array|max:12',
            'about_highlights.items.*.icon' => 'nullable|string|max:50',
            'about_highlights.items.*.title' => 'required|string|max:100',
            'about_highlights.items.*.description' => 'required|string|max:255',
            'shop_header' => 'nullable|array',
            'shop_header.label' => 'nullable|string|max:100',
            'shop_header.heading' => 'nullable|string|max:255',
            'shop_header.subtitle' => 'nullable|string|max:255',
            'shop_promo' => 'nullable|array',
            'shop_promo.badge' => 'nullable|string|max:100',
            'shop_promo.heading' => 'nullable|string|max:255',
            'shop_promo.subtitle' => 'nullable|string|max:500',
            'shop_promo.button_text' => 'nullable|string|max:100',
            'shop_promo.perks' => 'nullable|array|max:6',
            'shop_promo.perks.*' => 'required|string|max:100',
            'contact_page' => 'nullable|array',
            'contact_page.info_title' => 'nullable|string|max:100',
            'contact_page.info_subtitle' => 'nullable|string|max:255',
            'contact_page.info_description' => 'nullable|string|max:500',
            'contact_page.info_badge' => 'nullable|string|max:100',
            'contact_page.promises' => 'nullable|array|max:12',
            'contact_page.promises.*.icon' => 'nullable|string|max:100',
            'contact_page.promises.*.title' => 'required|string|max:100',
            'contact_page.promises.*.description' => 'required|string|max:255',
            'blog_page' => 'nullable|array',
            'blog_page.header' => 'nullable|array',
            'blog_page.header.label' => 'nullable|string|max:100',
            'blog_page.header.heading' => 'nullable|string|max:255',
            'blog_page.header.subtitle' => 'nullable|string|max:500',
            'blog_page.cta' => 'nullable|array',
            'blog_page.cta.heading' => 'nullable|string|max:255',
            'blog_page.cta.subtitle' => 'nullable|string|max:500',
            'blog_page.cta.button_label' => 'nullable|string|max:100',
            'blog_page.cta.button_link' => 'nullable|string|max:500',
            'blog_overlay_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'blog_overlay_opacity' => 'nullable|integer|min:0|max:100',
            'services_page' => 'nullable|array',
            'services_page.header' => 'nullable|array',
            'services_page.header.label' => 'nullable|string|max:100',
            'services_page.header.heading' => 'nullable|string|max:255',
            'services_page.header.subtitle' => 'nullable|string|max:500',
            'services_page.header.primary_cta' => 'nullable|array',
            'services_page.header.primary_cta.label' => 'nullable|string|max:100',
            'services_page.header.primary_cta.link' => 'nullable|string|max:500',
            'services_page.header.secondary_cta' => 'nullable|array',
            'services_page.header.secondary_cta.label' => 'nullable|string|max:100',
            'services_page.header.secondary_cta.link' => 'nullable|string|max:500',
            'services_page.summary' => 'nullable|array',
            'services_page.summary.items' => 'nullable|array|max:6',
            'services_page.summary.items.*.title' => 'required|string|max:100',
            'services_page.summary.items.*.description' => 'required|string|max:255',
            'services_page.stats' => 'nullable|array',
            'services_page.stats.items' => 'nullable|array|max:6',
            'services_page.stats.items.*.value' => 'required|string|max:50',
            'services_page.stats.items.*.label' => 'required|string|max:150',
            'services_page.cta' => 'nullable|array',
            'services_page.cta.heading' => 'nullable|string|max:255',
            'services_page.cta.subtitle' => 'nullable|string|max:500',
            'services_page.cta.button_label' => 'nullable|string|max:100',
            'services_page.cta.button_link' => 'nullable|string|max:500',
            'services_overlay_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'services_overlay_opacity' => 'nullable|integer|min:0|max:100',
            'modules_enabled' => 'nullable|array',
            'modules_enabled.shop' => 'nullable|boolean',
            'modules_enabled.blog' => 'nullable|boolean',
            'modules_enabled.services' => 'nullable|boolean',
            'modules_enabled.about' => 'nullable|boolean',
            'modules_enabled.contact' => 'nullable|boolean',
            'default_seo_title' => 'nullable|string|max:255',
            'default_seo_description' => 'nullable|string|max:500',
            'default_og_image_url' => 'nullable|url|max:500',
            'pages_seo' => 'nullable|array',
            'pages_seo.*.seo_title' => 'nullable|string|max:255',
            'pages_seo.*.seo_description' => 'nullable|string|max:500',
            'pages_seo.*.og_image_url' => 'nullable|url|max:500',
            'pages_seo.*.noindex' => 'nullable|boolean',
            'pages_seo.*.cover_alt_text' => 'nullable|string|max:255',
            'canonical_base_url' => 'nullable|url|max:500',
            'logo_alt_text' => 'nullable|string|max:255',
        ]);

        $this->validateShowcaseRules($validated);
        $this->validateWatchShopRules($validated);
        $this->validateBestSellersRules($validated);

        $config = SiteConfig::first() ?? SiteConfig::create([]);

        // Merge theme: keep existing values, override only what's sent
        if (isset($validated['theme'])) {
            $validated['theme'] = array_merge(
                $config->theme ?? SiteConfig::THEME_DEFAULTS,
                array_filter($validated['theme'], fn ($v) => $v !== null)
            );
        }

        // Preserve the video_status set by OptimizeShowcaseVideoJob — admin updates
        // never carry a status (the job owns that field) so we merge it back in.
        if (isset($validated['homepage_showcase'])) {
            $existing = is_array($config->homepage_showcase) ? $config->homepage_showcase : [];
            $existingStatus = $existing['video_status'] ?? null;
            if ($existingStatus !== null) {
                $validated['homepage_showcase']['video_status'] = $existingStatus;
            }
        }

        // Watch & Shop: reconcile cards. Per-card `media_status` is owned by
        // the optimize job, not the admin form, so merge it back. Cards that
        // were removed from the incoming list have their media + poster purged
        // so we don't leak orphan files.
        if (isset($validated['homepage_watch_shop'])) {
            $validated['homepage_watch_shop'] = $this->reconcileWatchShop(
                $config,
                $validated['homepage_watch_shop'],
            );
        }

        $config->update($validated);

        // return fresh data including urls
        return $this->show();
    }

    /**
     * Merge the admin-supplied Watch & Shop block with the existing one:
     * delete media (and per-card poster) for cards that were removed. Status is
     * tracked on each card's Media row directly, so it doesn't need merging.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function reconcileWatchShop(SiteConfig $config, array $incoming): array
    {
        $existing = is_array($config->homepage_watch_shop) ? $config->homepage_watch_shop : [];
        $existingCards = collect($existing['cards'] ?? [])->keyBy('id');

        $incomingIds = collect($incoming['cards'] ?? [])->pluck('id')->filter()->all();

        // Purge media + posters for cards that were removed.
        $removedIds = $existingCards->keys()->diff($incomingIds);
        foreach ($removedIds as $removedId) {
            $prior = $existingCards->get($removedId);
            $this->deleteWatchShopCardAssets($config, (string) $removedId, isset($prior['media_id']) ? (int) $prior['media_id'] : null);
        }

        return $incoming;
    }

    /**
     * Upload a video/image/gif for a single Watch & Shop card. The card_id is
     * generated server-side so the optimize job can target the right card and
     * the poster collection name is unique per card. The card itself isn't yet
     * persisted in `homepage_watch_shop` — the admin still has to assign a
     * product and save — but the Media row exists immediately so the form can
     * preview the upload while the optimize job runs in the background.
     */
    public function uploadWatchShopCard(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:mp4,webm,mov,quicktime,gif,jpeg,png,webp', 'max:25600'], // 25MB
        ]);

        $config = SiteConfig::first() ?? SiteConfig::create([]);
        $cardId = (string) Str::uuid();

        $media = $this->mediaService->addToCollection(
            $validated['file'],
            $config,
            'watch_shop_card',
            'site-config',
        );

        $mime = strtolower((string) $media->mime_type);
        $isVideoLike = str_starts_with($mime, 'video/') || $mime === 'image/gif';

        // Stamp status on the Media row up front so any /site-config read
        // between the dispatch and the job picking up the work shows the
        // spinner — not an incorrect "ready". The job will flip this to
        // 'ready' or 'failed' on completion.
        $media->update(['processing_status' => $isVideoLike ? 'processing' : 'ready']);

        if ($isVideoLike) {
            OptimizeWatchShopMediaJob::dispatch($media->id, $cardId);
        }

        return response()->json([
            'card_id' => $cardId,
            'media_id' => $media->id,
            'media_url' => $media->url,
            'media_kind' => str_starts_with($mime, 'video/') ? 'video' : 'image',
            'media_status' => $isVideoLike ? 'processing' : 'ready',
        ]);
    }

    /**
     * Hard-delete a Watch & Shop card's media + poster. Used when the admin
     * removes a card from the list before saving the wider site_config.
     */
    public function deleteWatchShopCard(string $cardId)
    {
        $config = SiteConfig::first();
        if (! $config) {
            return response()->noContent();
        }

        $cards = collect(($config->homepage_watch_shop['cards'] ?? []));
        $prior = $cards->firstWhere('id', $cardId);
        $mediaId = $prior !== null && isset($prior['media_id']) ? (int) $prior['media_id'] : null;

        $this->deleteWatchShopCardAssets($config, $cardId, $mediaId);

        // Drop the card from the JSON, too — keeps everything consistent if
        // the admin doesn't subsequently save the form.
        $remaining = $cards->reject(fn ($c) => ($c['id'] ?? null) === $cardId)->values()->all();
        $watchShop = $config->homepage_watch_shop ?? [];
        $watchShop['cards'] = $remaining;
        $config->update(['homepage_watch_shop' => $watchShop]);

        return response()->noContent();
    }

    private function deleteWatchShopCardAssets(SiteConfig $config, string $cardId, ?int $mediaId): void
    {
        if ($mediaId !== null) {
            $media = Media::find($mediaId);
            if ($media) {
                Storage::disk('public')->delete($media->path);
                $media->delete();
            }
        }

        $poster = $config->media()->where('collection', 'watch_shop_poster:'.$cardId)->first();
        if ($poster) {
            Storage::disk('public')->delete($poster->path);
            $poster->delete();
        }
    }

    public function uploadMedia(Request $request, string $collection)
    {
        $allowed = ['logo', 'favicon', 'cart_icon', 'hero', 'about', 'contact', 'blog', 'services', 'showcase_video'];
        abort_unless(in_array($collection, $allowed, true), 404);

        $max = match (true) {
            in_array($collection, ['logo', 'favicon', 'cart_icon'], true) => 2048,    // 2MB
            $collection === 'showcase_video' => 25600,                                 // 25MB
            default => 10120,                                                          // 10MB
        };

        $mimes = match ($collection) {
            'hero' => 'jpeg,png,gif,webp,svg,svgz,mp4,webm',
            'showcase_video' => 'mp4,webm,mov,quicktime',
            default => 'jpeg,png,gif,webp,svg,svgz',
        };

        $validated = $request->validate([
            'file' => ['required', 'file', "mimes:$mimes", "max:$max"],
        ]);

        $config = SiteConfig::first() ?? SiteConfig::create([]);

        $media = $this->mediaService->upload(
            $validated['file'],
            $config,
            $collection,
            'site-config'
        );

        // Clear any preset/external hero URL override so the uploaded file wins.
        if ($collection === 'hero') {
            $config->update(['hero_image_url' => null, 'hero_media_mime' => null]);
        }

        // Showcase video: drop any stale poster, mark as processing, dispatch
        // FFmpeg job to faststart-encode the upload + generate a poster frame.
        if ($collection === 'showcase_video') {
            $existingPoster = $config->media()->where('collection', 'showcase_video_poster')->first();
            if ($existingPoster) {
                Storage::disk('public')->delete($existingPoster->path);
                $existingPoster->delete();
            }

            $showcase = $config->homepage_showcase ?? [];
            $showcase['video_status'] = 'processing';
            $config->update(['homepage_showcase' => $showcase]);

            OptimizeShowcaseVideoJob::dispatch($media->id);
        }

        return response()->json([
            'id' => $media->id,
            'collection' => $media->collection,
            'url' => $media->url,
        ]);
    }

    public function deleteMedia(string $collection)
    {
        $allowed = ['logo', 'favicon', 'cart_icon', 'hero', 'about', 'contact', 'blog', 'services', 'showcase_video'];
        abort_unless(in_array($collection, $allowed, true), 404);

        $config = SiteConfig::first();
        if (! $config) {
            return response()->noContent();
        }

        $collectionsToClear = $collection === 'showcase_video'
            ? ['showcase_video', 'showcase_video_poster']
            : [$collection];

        foreach ($collectionsToClear as $col) {
            $media = $config->media()->where('collection', $col)->first();
            if (! $media) {
                continue;
            }
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        if ($collection === 'showcase_video') {
            $showcase = $config->homepage_showcase ?? [];
            $showcase['video_status'] = 'idle';
            $config->update(['homepage_showcase' => $showcase]);
        }

        return response()->noContent();
    }
}
