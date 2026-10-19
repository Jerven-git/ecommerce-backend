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
        Schema::create('site_config_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamp('base_updated_at');
            $table->string('base_hash', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('review_status', 32)->default('draft')->index();
            $table->foreignId('review_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_requested_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('scheduled_publish_at')->nullable()->index();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scheduled_release_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_config_drafts');
    }
};
