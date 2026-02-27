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
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->enum('zone_type', ['own_city', 'own_state', 'own_country', 'other_country']);
            $table->boolean('enabled')->default(true);
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->decimal('per_kg_rate', 10, 2)->default(0);
            $table->decimal('per_cbm_rate', 10, 2)->default(0);
            $table->timestamps();
            
            $table->unique('zone_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_zones');
    }
};
