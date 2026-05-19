<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->foreignId('store_id')
                ->nullable()
                ->after('properties')
                ->constrained('stores')
                ->nullOnDelete();

            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['store_id', 'created_at']);
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
