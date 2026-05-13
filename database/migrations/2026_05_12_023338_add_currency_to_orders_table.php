<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('currency', 3)->default('USD')->after('total_amount');
            $table->decimal('exchange_rate', 16, 8)->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
    }
};
