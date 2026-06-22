<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
            $table->text('image_url')->nullable()->after('slug');
            $table->unsignedTinyInteger('overlay_opacity')->nullable()->after('image_url');
        });

        // Backfill a unique-per-store slug for existing rows.
        $rows = DB::table('categories')->select('id', 'store_id', 'name')->orderBy('id')->get();
        $usedByStore = [];
        foreach ($rows as $row) {
            $base = Str::slug($row->name) ?: 'category';
            $slug = $base;
            $i = 1;
            $used = $usedByStore[$row->store_id] ?? [];
            while (in_array($slug, $used, true)) {
                $slug = "{$base}-{$i}";
                $i++;
            }
            $used[] = $slug;
            $usedByStore[$row->store_id] = $used;

            DB::table('categories')->where('id', $row->id)->update(['slug' => $slug]);
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->unique(['store_id', 'slug'], 'categories_store_id_slug_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_store_id_slug_unique');
            $table->dropColumn(['slug', 'image_url', 'overlay_opacity']);
        });
    }
};
