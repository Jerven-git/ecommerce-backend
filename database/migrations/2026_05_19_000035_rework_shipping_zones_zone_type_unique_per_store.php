<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_zones', function (Blueprint $table): void {
            $table->dropUnique('shipping_zones_zone_type_unique');
            $table->unique(['store_id', 'zone_type'], 'shipping_zones_store_id_zone_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_zones', function (Blueprint $table): void {
            $table->dropUnique('shipping_zones_store_id_zone_type_unique');
            $table->unique('zone_type', 'shipping_zones_zone_type_unique');
        });
    }
};
