<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table): void {
            $table->dropUnique('discounts_code_unique');
            $table->unique(['store_id', 'code'], 'discounts_store_id_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table): void {
            $table->dropUnique('discounts_store_id_code_unique');
            $table->unique('code', 'discounts_code_unique');
        });
    }
};
