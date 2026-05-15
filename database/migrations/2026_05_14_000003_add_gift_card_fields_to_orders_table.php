<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Applied gift card code at checkout (redemption)
            $table->string('gift_card_code', 20)->nullable()->after('discount_amount');
            $table->decimal('gift_card_amount', 10, 2)->default(0)->after('gift_card_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gift_card_code', 'gift_card_amount']);
        });
    }
};
