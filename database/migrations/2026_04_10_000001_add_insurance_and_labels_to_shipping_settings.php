<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_settings', function (Blueprint $table) {
            $table->decimal('insurance_rate_percent', 5, 2)->default(0)->after('insurance_fee');
            $table->decimal('insurance_min_fee', 10, 2)->default(0)->after('insurance_rate_percent');
            $table->string('express_label', 100)->default('Express Post')->after('insurance_min_fee');
            $table->string('express_pricing_mode', 20)->default('flat')->after('express_label');
            $table->json('express_weight_tiers')->nullable()->after('express_pricing_mode');
            $table->string('registered_label', 100)->default('Registered Post')->after('express_weight_tiers');
            $table->string('insurance_label', 100)->default('Shipping Insurance')->after('registered_label');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_settings', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_rate_percent',
                'insurance_min_fee',
                'express_label',
                'express_pricing_mode',
                'express_weight_tiers',
                'registered_label',
                'insurance_label',
            ]);
        });
    }
};
