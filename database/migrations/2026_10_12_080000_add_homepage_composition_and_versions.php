<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->json('homepage_sections')->nullable()->after('homepage_best_sellers');
        });

        Schema::create('site_config_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_config_versions');

        Schema::table('site_config', function (Blueprint $table) {
            $table->dropColumn('homepage_sections');
        });
    }
};
