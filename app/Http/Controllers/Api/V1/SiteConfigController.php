<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\OptimizeShowcaseVideoJob;
use App\Models\Product;
use App\Models\SiteConfig;
use App\Modules\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        ]);

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

        $config->update($validated);

        // return fresh data including urls
        return $this->show();
    }

    public function uploadMedia(Request $request, string $collection)
    {
        $allowed = ['logo', 'favicon', 'cart_icon', 'hero', 'about', 'contact', 'blog', 'services', 'showcase_video'];
        abort_unless(in_array($collection, $allowed, true), 404);

        $max = match (true) {
            in_array($collection, ['logo', 'favicon', 'cart_icon'], true) => 2048,    // 2MB
            $collection === 'showcase_video' => 102400,                                // 100MB
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
