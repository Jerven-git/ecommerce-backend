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
        Schema::create('shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('express_post_fee', 10, 2)->default(0);
            $table->decimal('registered_post_fee', 10, 2)->default(0);
            $table->decimal('insurance_fee', 10, 2)->default(0);
            $table->decimal('insurance_rate_percent', 5, 2)->default(0);
            $table->decimal('insurance_min_fee', 10, 2)->default(0);
            $table->string('express_label', 100)->default('Express Post');
            $table->string('express_pricing_mode', 20)->default('flat');
            $table->json('express_weight_tiers')->nullable();
            $table->string('registered_label', 100)->default('Registered Post');
            $table->string('insurance_label', 100)->default('Shipping Insurance');
            $table->decimal('free_shipping_threshold', 10, 2)->default(0);
            $table->string('store_country')->default('');
            $table->string('store_state')->default('');
            $table->string('store_city')->default('');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_settings');
    }
};
