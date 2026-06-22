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
        Schema::table('products', function (Blueprint $table): void {
            // Secondary image revealed on hover, plus optional product specs
            // shown on the detail page (material, physical dimensions).
            $table->text('hover_image_url')->nullable()->after('image_url');
            $table->string('material')->nullable()->after('hover_image_url');
            $table->string('dimensions')->nullable()->after('material');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['hover_image_url', 'material', 'dimensions']);
        });
    }
};
