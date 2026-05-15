<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->foreign('welcome_popup_discount_id')->references('id')->on('discounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->dropForeign(['welcome_popup_discount_id']);
        });
    }
};
