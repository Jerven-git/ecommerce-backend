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
            $table->string('primary_color')->default('#6898ED');
            $table->string('secondary_color')->default('#4B5979');
            $table->string('heading_font')->nullable()->default('Inter');
            $table->string('body_font')->nullable()->default('Inter');
            $table->string('logo_url')->nullable();
            $table->string('hero_title')->default('Welcome to Our Store');
            $table->string('hero_subtitle')->default('Discover amazing products at great prices');
            $table->text('about_content')->nullable();
            $table->string('contact_email')->default('contact@store.com');
            $table->string('contact_phone')->default('+1234567890');
            $table->json('contact_entries')->nullable();
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
