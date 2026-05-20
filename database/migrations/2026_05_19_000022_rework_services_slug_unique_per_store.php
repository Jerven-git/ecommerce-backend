<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropUnique('services_slug_unique');
            $table->unique(['store_id', 'slug'], 'services_store_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropUnique('services_store_id_slug_unique');
            $table->unique('slug', 'services_slug_unique');
        });
    }
};
