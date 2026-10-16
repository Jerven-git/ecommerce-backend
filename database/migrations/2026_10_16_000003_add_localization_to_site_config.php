<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->string('default_locale', 10)->default('en')->after('site_name');
            $table->json('supported_locales')->nullable()->after('default_locale');
            $table->json('localized_content')->nullable()->after('supported_locales');
        });
    }

    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->dropColumn(['default_locale', 'supported_locales', 'localized_content']);
        });
    }
};
