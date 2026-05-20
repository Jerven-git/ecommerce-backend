<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_slug_unique');
            $table->unique(['store_id', 'slug'], 'products_store_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_store_id_slug_unique');
            $table->unique('slug', 'products_slug_unique');
        });
    }
};
