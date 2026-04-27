<?php

use App\Models\Media;
use App\Models\SiteConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Retire the legacy services_page.groups JSON layout: services and
     * categories are first-class tables now (see services + service_categories
     * migrations). Strips the groups key from site_config.services_page and
     * sweeps any leftover services_gallery media rows + storage files.
     */
    public function up(): void
    {
        // 1) Sweep any leftover services_gallery media for SiteConfig rows.
        $orphans = Media::where('imageable_type', SiteConfig::class)
            ->where('collection', 'services_gallery')
            ->get();

        foreach ($orphans as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        // 2) Strip the groups key from each site_config.services_page JSON.
        $rows = DB::table('site_config')->get(['id', 'services_page']);
        foreach ($rows as $row) {
            if (!$row->services_page) continue;

            $sp = json_decode($row->services_page, true);
            if (!is_array($sp) || !array_key_exists('groups', $sp)) continue;

            unset($sp['groups']);
            DB::table('site_config')
                ->where('id', $row->id)
                ->update(['services_page' => json_encode($sp)]);
        }
    }

    /**
     * Irreversible by design — the groups data is now in services /
     * service_categories tables and the legacy media files have been deleted
     * from disk. Rolling back this migration only restores an empty groups
     * array on the JSON, so older code paths don't crash if reverted.
     */
    public function down(): void
    {
        $rows = DB::table('site_config')->get(['id', 'services_page']);
        foreach ($rows as $row) {
            if (!$row->services_page) continue;

            $sp = json_decode($row->services_page, true);
            if (!is_array($sp)) continue;

            $sp['groups'] = [];
            DB::table('site_config')
                ->where('id', $row->id)
                ->update(['services_page' => json_encode($sp)]);
        }
    }
};
