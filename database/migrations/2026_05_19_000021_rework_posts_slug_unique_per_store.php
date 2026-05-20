<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropUnique('posts_slug_unique');
            $table->unique(['store_id', 'slug'], 'posts_store_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropUnique('posts_store_id_slug_unique');
            $table->unique('slug', 'posts_slug_unique');
        });
    }
};
