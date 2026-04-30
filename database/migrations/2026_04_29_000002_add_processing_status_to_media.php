<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // null = not applicable (e.g. plain images that never run a job).
            // 'processing' | 'ready' | 'failed' once the optimize job touches the row.
            $table->string('processing_status', 20)->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('processing_status');
        });
    }
};
