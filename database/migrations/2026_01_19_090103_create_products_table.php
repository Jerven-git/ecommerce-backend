<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('og_image_url', 500)->nullable();
            $table->boolean('noindex')->default(false);
            $table->decimal('price', 10, 2)->default(0);
            $table->text('image_url')->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('allow_backorder')->default(false);
            $table->enum('backorder_charge_policy', ['charged_now', 'charged_later'])
                ->default('charged_later');
            $table->decimal('weight', 8, 2)->default(0);
            $table->decimal('length_cm', 8, 2)->default(0);
            $table->decimal('width_cm', 8, 2)->default(0);
            $table->decimal('height_cm', 8, 2)->default(0);
            $table->enum('shipping_calc_type', ['weight', 'dimensions'])->default('weight');
            $table->string('category')->default('general');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('hover_zoom_enabled')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
