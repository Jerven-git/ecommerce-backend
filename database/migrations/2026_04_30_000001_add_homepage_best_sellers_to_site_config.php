<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->json('homepage_best_sellers')->nullable()->after('homepage_watch_shop');
        });
    }

    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->dropColumn('homepage_best_sellers');
        });
    }
};
