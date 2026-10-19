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
        Schema::create('site_config', function (Blueprint $table) {
            $table->id();
            $table->json('theme')->nullable();
            $table->string('about_overlay_color', 7)->default('#000000');
            $table->unsignedTinyInteger('about_overlay_opacity')->default(45);
            $table->string('contact_overlay_color', 7)->default('#000000');
            $table->unsignedTinyInteger('contact_overlay_opacity')->default(45);
            $table->string('badge_in_stock_color', 7)->default('#16a34a');
            $table->string('contact_email')->default('contact@store.com');
            $table->json('contact_entries')->nullable();
            $table->boolean('favorites_enabled')->default(false);
            $table->boolean('show_stock_quantity')->default(false);
            $table->boolean('backorder_enabled')->default(false);
            $table->unsignedInteger('backorder_payment_link_expiry_hours')->default(24);
            $table->unsignedBigInteger('welcome_popup_discount_id')->nullable();
            $table->json('homepage_showcase')->nullable();
            $table->json('homepage_watch_shop')->nullable();
            $table->json('homepage_best_sellers')->nullable();
            $table->string('blog_overlay_color', 7)->default('#000000');
            $table->integer('blog_overlay_opacity')->default(40);
            $table->string('services_overlay_color', 7)->default('#000000');
            $table->integer('services_overlay_opacity')->default(40);
            $table->json('modules_enabled')->nullable();
            $table->string('default_og_image_url', 500)->nullable();
            $table->string('canonical_base_url', 500)->nullable();
            $table->string('loader_animation', 20)->default('bounce');
            $table->string('currency_code', 3)->default('USD');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_config');
    }
};
