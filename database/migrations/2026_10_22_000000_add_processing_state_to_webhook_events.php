<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_webhook_events', 'subscription_webhook_events'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('last_error')->nullable();
            });

            // Existing rows were written by the old "store means processed"
            // contract and must remain deduplicated after this migration.
            DB::table($tableName)->update([
                'attempts' => 1,
                'processed_at' => DB::raw('updated_at'),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['payment_webhook_events', 'subscription_webhook_events'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['attempts', 'processed_at', 'failed_at', 'last_error']);
            });
        }
    }
};
