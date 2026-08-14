<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table): void {
            $table->string('loader_animation', 20)->default('bounce')->after('footer_logo_size');
        });
    }

    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table): void {
            $table->dropColumn('loader_animation');
        });
    }
};
