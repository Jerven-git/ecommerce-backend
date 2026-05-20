<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained('stores')
                ->cascadeOnDelete();
        });

        $defaultStoreId = DB::table('stores')->where('slug', 'default')->value('id');

        if ($defaultStoreId !== null) {
            DB::table('posts')
                ->whereNull('store_id')
                ->update(['store_id' => $defaultStoreId]);
        }

        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->index('store_id', 'posts_store_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_store_id_index');
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
