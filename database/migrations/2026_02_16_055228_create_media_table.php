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
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->morphs('imageable');

            $table->string('collection')->default('default');

            $table->string('hash');
            $table->string('path');
            $table->string('format');
            $table->string('mime_type');
            $table->integer('size');
            $table->string('processing_status', 20)->nullable();
            $table->string('alt_text')->nullable();
            $table->timestamps();

            $table->index(['imageable_type', 'imageable_id', 'collection']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
