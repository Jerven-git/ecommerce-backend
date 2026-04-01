<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->foreignId('welcome_popup_discount_id')->nullable()->constrained('discounts')->nullOnDelete();
        });

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('source')->default('welcome_popup');
            $table->string('discount_code_sent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table) {
            $table->dropForeign(['welcome_popup_discount_id']);
            $table->dropColumn('welcome_popup_discount_id');
        });

        Schema::dropIfExists('subscribers');
    }
};
