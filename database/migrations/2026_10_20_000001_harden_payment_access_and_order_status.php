<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Forward-only containment for live databases. Fresh databases already use
     * the corrected order enum in the original create-table migration.
     */
    public function up(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'public_token')) {
            throw new RuntimeException('Containment migration requires payments.public_token to exist.');
        }

        DB::table('payments')
            ->whereNull('public_token')
            ->orderBy('id')
            ->chunkById(100, function ($payments): void {
                foreach ($payments as $payment) {
                    DB::table('payments')
                        ->where('id', $payment->id)
                        ->whereNull('public_token')
                        ->update(['public_token' => Str::random(64)]);
                }
            });

        if (DB::table('payments')->whereNull('public_token')->exists()) {
            throw new RuntimeException('Containment migration could not backfill every payment access token.');
        }

        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'status')) {
            throw new RuntimeException('Containment migration requires orders.status to exist.');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','processing','shipped','delivered','cancelled','backorder_awaiting_stock','backorder_notified','backorder_expired','backorder_cancelled','failed_needs_refund') NOT NULL DEFAULT 'pending', ALGORITHM=INPLACE, LOCK=NONE");
        }
    }

    /**
     * Removing either capability could invalidate existing rows or links.
     */
    public function down(): void
    {
        // Intentionally irreversible.
    }
};
