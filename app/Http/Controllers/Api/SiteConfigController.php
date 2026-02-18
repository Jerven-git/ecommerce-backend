<?php

namespace App\Http\Controllers\Api;

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
        return SiteConfig::with(['logoMedia', 'heroMedia', 'aboutMedia', 'contactMedia'])->first()
            ?? SiteConfig::create([]);
    }

    public function show()
    {
        $config = $this->config();

        return response()->json([
            'data' => [
                'id' => $config->id,
                'site_name' => $config->site_name,
                'primary_color' => $config->primary_color,
                'secondary_color' => $config->secondary_color,
                'hero_title' => $config->hero_title,
                'hero_subtitle' => $config->hero_subtitle,
                'about_content' => $config->about_content,
                'contact_email' => $config->contact_email,
                'contact_phone' => $config->contact_phone,
                'updated_at' => $config->updated_at,

                // ✅ urls come from media
                'logo_url' => optional($config->logoMedia)->url,
                'hero_image_url' => optional($config->heroMedia)->url,
                'about_image_url' => optional($config->aboutMedia)->url,
                'contact_image_url' => optional($config->contactMedia)->url,
            ]
        ]);
    }

    public function update(Request $request)
    {
        // ✅ remove dd()

        $validated = $request->validate([
            'site_name' => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'hero_title' => 'nullable|string|max:255',
            'hero_subtitle' => 'nullable|string|max:255',
            'about_content' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:20',
        ]);

        $config = SiteConfig::first() ?? SiteConfig::create([]);
        $config->update($validated);

        // return fresh data including urls
        return $this->show();
    }

    public function uploadMedia(Request $request, string $collection)
    {
        // dd([
        //     'collection' => $collection,
        //     'content_type' => $request->header('content-type'),
        //     'has_file' => $request->hasFile('file'),
        //     'file' => $request->file('file'),
        //     'all' => $request->all(),
        // ]);
        abort_unless(in_array($collection, ['logo', 'hero', 'about', 'contact']), 404);

        $max = $collection === 'logo' ? 2048 : 10120; // KB (2MB vs 10MB)

        $validated = $request->validate([
            'file' => ['required', 'file', 'image', "max:$max"],
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
        abort_unless(in_array($collection, ['logo', 'hero', 'about', 'contact']), 404);

        $config = SiteConfig::first();
        if (!$config) return response()->noContent();

        $media = $config->media()->where('collection', $collection)->first();
        if (!$media) return response()->noContent();

        Storage::disk('public')->delete($media->path);
        $media->delete();

        return response()->noContent();
    }
}