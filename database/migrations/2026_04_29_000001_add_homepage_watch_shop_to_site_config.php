<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->json('homepage_watch_shop')->nullable()->after('homepage_showcase');
        });
    }

    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->dropColumn('homepage_watch_shop');
        });
    }
};
