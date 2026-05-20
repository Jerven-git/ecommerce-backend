<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table): void {
            $table->dropUnique('service_categories_slug_unique');
            $table->unique(['store_id', 'slug'], 'service_categories_store_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table): void {
            $table->dropUnique('service_categories_store_id_slug_unique');
            $table->unique('slug', 'service_categories_slug_unique');
        });
    }
};
