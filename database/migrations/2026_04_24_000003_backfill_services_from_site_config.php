<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Migrate existing services_page.groups JSON from site_configs into the
     * new service_categories + services tables. Idempotent via a marker key
     * we write back to services_page so re-running doesn't duplicate rows.
     */
    public function up(): void
    {
        $row = DB::table('site_configs')->first();
        if (!$row || !$row->services_page) {
            return;
        }

        $page = json_decode($row->services_page, true);
        if (!is_array($page) || !empty($page['_backfilled'])) {
            return;
        }

        $groups = $page['groups'] ?? [];
        if (!is_array($groups) || count($groups) === 0) {
            $this->markBackfilled($row->id, $page);
            return;
        }

        $now = now();
        $usedCategorySlugs = [];

        foreach ($groups as $gi => $group) {
            $name = trim((string) ($group['heading'] ?? 'Services'));
            if ($name === '') {
                $name = "Services {$gi}";
            }

            $slug = $this->uniqueSlug(Str::slug($name), $usedCategorySlugs);
            $usedCategorySlugs[] = $slug;

            $categoryId = DB::table('service_categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'gradient_from' => '#6898ED',
                'gradient_to' => '#4B5979',
                'overlay_opacity' => 60,
                'sort_order' => $gi,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $usedServiceSlugs = [];
            foreach (($group['items'] ?? []) as $ii => $item) {
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $serviceSlug = $this->uniqueServiceSlug(Str::slug($title), $usedServiceSlugs);
                $usedServiceSlugs[] = $serviceSlug;

                $description = (string) ($item['description'] ?? '');

                DB::table('services')->insert([
                    'slug' => $serviceSlug,
                    'title' => $title,
                    'eyebrow' => $item['eyebrow'] ?? null,
                    'description' => $description !== '' ? $description : null,
                    // Seed body with the description so detail pages aren't
                    // empty before admins fill them in. They can edit later.
                    'body' => $description !== '' ? $description : null,
                    'cover_image_url' => $item['image_url'] ?? null,
                    'category_id' => $categoryId,
                    'cta_label' => $item['cta_label'] ?? null,
                    'cta_link' => $item['cta_link'] ?? null,
                    'is_published' => true,
                    'is_featured' => false,
                    'published_at' => $now,
                    'sort_order' => $ii,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->markBackfilled($row->id, $page);
    }

    public function down(): void
    {
        // Wipe rows + clear the backfill marker so up() can be re-run.
        DB::table('services')->delete();
        DB::table('service_categories')->delete();

        $row = DB::table('site_configs')->first();
        if ($row && $row->services_page) {
            $page = json_decode($row->services_page, true);
            if (is_array($page)) {
                unset($page['_backfilled']);
                DB::table('site_configs')
                    ->where('id', $row->id)
                    ->update(['services_page' => json_encode($page)]);
            }
        }
    }

    private function markBackfilled(int $configId, array $page): void
    {
        $page['_backfilled'] = true;
        DB::table('site_configs')
            ->where('id', $configId)
            ->update(['services_page' => json_encode($page)]);
    }

    private function uniqueSlug(string $slug, array $used): string
    {
        if ($slug === '') {
            $slug = 'category';
        }
        $original = $slug;
        $n = 1;
        while (
            in_array($slug, $used, true)
            || DB::table('service_categories')->where('slug', $slug)->exists()
        ) {
            $slug = "{$original}-{$n}";
            $n++;
        }
        return $slug;
    }

    private function uniqueServiceSlug(string $slug, array $used): string
    {
        if ($slug === '') {
            $slug = 'service';
        }
        $original = $slug;
        $n = 1;
        while (
            in_array($slug, $used, true)
            || DB::table('services')->where('slug', $slug)->exists()
        ) {
            $slug = "{$original}-{$n}";
            $n++;
        }
        return $slug;
    }
};
