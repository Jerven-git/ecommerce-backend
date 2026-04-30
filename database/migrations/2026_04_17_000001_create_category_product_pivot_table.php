<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'category_id']);
        });

        // Migrate existing category_id data into the pivot table.
        // Use CURRENT_TIMESTAMP — works on MySQL, Postgres, AND sqlite (NOW()
        // is MySQL-only and broke `php artisan test` once the test DB was
        // correctly isolated to sqlite).
        DB::statement('
            INSERT INTO category_product (product_id, category_id, created_at, updated_at)
            SELECT id, category_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM products
            WHERE category_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
