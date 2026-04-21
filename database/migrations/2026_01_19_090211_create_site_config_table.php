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
            $table->string('site_name')->default('My Store');
            $table->json('theme')->nullable();
            $table->string('hero_title')->default('Welcome to Our Store');
            $table->string('hero_subtitle')->default('Discover amazing products at great prices');
            $table->string('hero_overlay_color', 7)->default('#000000');
            $table->unsignedTinyInteger('hero_overlay_opacity')->default(45);
            // When true, hero image flows under the (transparent) header.
            // When false (default), header sits as a solid band above the hero.
            $table->boolean('hero_full_bleed')->default(false);
            // Focal point as percentages (0–100). Maps to CSS `object-position`
            // so the chosen spot stays visible when the hero is cropped for
            // different viewport aspect ratios. Default 50/50 = centre.
            $table->unsignedTinyInteger('hero_focal_x')->default(50);
            $table->unsignedTinyInteger('hero_focal_y')->default(50);
            $table->string('hero_image_url')->nullable();
            $table->string('hero_media_mime')->nullable();
            $table->text('about_content')->nullable();
            $table->string('about_overlay_color', 7)->default('#000000');
            $table->unsignedTinyInteger('about_overlay_opacity')->default(45);
            $table->string('contact_overlay_color', 7)->default('#000000');
            $table->unsignedTinyInteger('contact_overlay_opacity')->default(45);
            $table->string('contact_email')->default('contact@store.com');
            $table->string('contact_phone')->default('+1234567890');
            $table->json('contact_entries')->nullable();
            $table->boolean('favorites_enabled')->default(false);
            $table->boolean('show_stock_quantity')->default(false);
            $table->boolean('backorder_enabled')->default(false);
            $table->unsignedInteger('backorder_payment_link_expiry_hours')->default(24);
            $table->boolean('welcome_popup_enabled')->default(false);
            $table->string('welcome_popup_heading')->default('Get 10% Off');
            $table->string('welcome_popup_body', 1000)->default('Sign up and get a discount code sent right to your inbox.');
            $table->json('homepage_steps')->nullable();
            $table->json('homepage_features')->nullable();
            $table->json('homepage_stats')->nullable();
            $table->json('homepage_newsletter')->nullable();
            $table->json('about_highlights')->nullable();
            $table->json('shop_header')->nullable();
            $table->json('shop_promo')->nullable();
            $table->json('contact_page')->nullable();
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
