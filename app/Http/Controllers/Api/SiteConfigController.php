<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteConfig;
use Illuminate\Http\Request;

class SiteConfigController extends Controller
{
    public function show()
    {
        $config = SiteConfig::first();
        
        if (!$config) {
            $config = SiteConfig::create([]);
        }

        return response()->json(['data' => $config]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'site_name' => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'logo_url' => 'nullable|string',
            'hero_title' => 'nullable|string|max:255',
            'hero_subtitle' => 'nullable|string|max:255',
            'about_content' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:20',
        ]);

        $config = SiteConfig::first();
        
        if (!$config) {
            $config = SiteConfig::create($validated);
        } else {
            $config->update($validated);
        }

        return response()->json([
            'message' => 'Site configuration updated successfully',
            'data' => $config
        ]);
    }
}