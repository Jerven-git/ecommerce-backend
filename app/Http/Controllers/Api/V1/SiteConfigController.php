<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SiteConfig;
use Illuminate\Http\Request;
use App\Modules\Media\MediaService;
use Illuminate\Support\Facades\Storage;

class SiteConfigController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    private function config(): SiteConfig
    {
        return SiteConfig::with(['logoMedia', 'faviconMedia', 'cartIconMedia', 'heroMedia', 'aboutMedia', 'contactMedia'])->first()
            ?? SiteConfig::create([]);
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
                'about_content' => $config->about_content,
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
                'updated_at' => $config->updated_at,

                // urls come from media
                'logo_url' => optional($config->logoMedia)->url,
                'favicon_url' => optional($config->faviconMedia)->url,
                'cart_icon_url' => optional($config->cartIconMedia)->url,
                'hero_image_url' => optional($config->heroMedia)->url,
                'hero_media_mime' => optional($config->heroMedia)->mime_type,
                'about_image_url' => optional($config->aboutMedia)->url,
                'contact_image_url' => optional($config->contactMedia)->url,
            ]
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
            'about_content' => 'nullable|string',
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
            'homepage_steps.items' => 'nullable|array|max:6',
            'homepage_steps.items.*.title' => 'required|string|max:100',
            'homepage_steps.items.*.description' => 'required|string|max:255',
            'homepage_features' => 'nullable|array',
            'homepage_features.items' => 'nullable|array|max:6',
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
        ]);

        $config = SiteConfig::first() ?? SiteConfig::create([]);

        // Merge theme: keep existing values, override only what's sent
        if (isset($validated['theme'])) {
            $validated['theme'] = array_merge(
                $config->theme ?? SiteConfig::THEME_DEFAULTS,
                array_filter($validated['theme'], fn ($v) => $v !== null)
            );
        }

        $config->update($validated);

        // return fresh data including urls
        return $this->show();
    }

    public function uploadMedia(Request $request, string $collection)
    {
        abort_unless(in_array($collection, ['logo', 'favicon', 'cart_icon', 'hero', 'about', 'contact']), 404);

        $max = in_array($collection, ['logo', 'favicon', 'cart_icon']) ? 2048 : 10120; // KB (2MB vs 10MB)

        $mimes = 'jpeg,png,gif,webp,svg,svgz';
        if ($collection === 'hero') {
            $mimes .= ',mp4,webm';
        }

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

        return response()->json([
            'id' => $media->id,
            'collection' => $media->collection,
            'url' => $media->url,
        ]);
    }

    public function deleteMedia(string $collection)
    {
        abort_unless(in_array($collection, ['logo', 'favicon', 'cart_icon', 'hero', 'about', 'contact']), 404);

        $config = SiteConfig::first();
        if (!$config) return response()->noContent();

        $media = $config->media()->where('collection', $collection)->first();
        if (!$media) return response()->noContent();

        Storage::disk('public')->delete($media->path);
        $media->delete();

        return response()->noContent();
    }
}