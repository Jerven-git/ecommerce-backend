<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config_versions', function (Blueprint $table) {
            $table->string('release_note', 500)->nullable()->after('published_by');
            $table->json('changed_areas')->nullable()->after('release_note');
        });
    }

    public function down(): void
    {
        Schema::table('site_config_versions', function (Blueprint $table) {
            $table->dropColumn(['release_note', 'changed_areas']);
        });
    }
};
