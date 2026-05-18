<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config', function (Blueprint $table): void {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained('stores')
                ->cascadeOnDelete();
        });

        $defaultStoreId = DB::table('stores')->where('slug', 'default')->value('id');

        if ($defaultStoreId !== null) {
            DB::table('site_config')
                ->whereNull('store_id')
                ->update(['store_id' => $defaultStoreId]);
        }

        Schema::table('site_config', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->unique('store_id', 'site_config_store_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('site_config', function (Blueprint $table): void {
            $table->dropUnique('site_config_store_id_unique');
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
