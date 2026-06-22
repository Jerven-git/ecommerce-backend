<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table): void {
            // Editorial "statement" band on the homepage (quote + image).
            $table->json('homepage_statement')->nullable()->after('homepage_stats');
            // Optional richer "Our Story" page (hero + two alternating
            // image/text sections with CTAs).
            $table->json('story_page')->nullable()->after('about_image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table): void {
            $table->dropColumn(['homepage_statement', 'story_page']);
        });
    }
};
