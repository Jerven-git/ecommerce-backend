<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('eyebrow', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->longText('body')->nullable();
            $table->string('cover_image_url', 500)->nullable();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('service_categories')
                ->nullOnDelete();
            $table->string('cta_label', 100)->nullable();
            $table->string('cta_link', 500)->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('og_image_url', 500)->nullable();
            $table->boolean('noindex')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
            $table->index('is_featured');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
