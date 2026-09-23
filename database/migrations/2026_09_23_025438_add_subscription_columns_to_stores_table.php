<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('subscription_status', 20)->default('unsubscribed')->after('status');
            $table->foreignId('subscription_plan_id')
                ->nullable()
                ->after('subscription_status')
                ->constrained('subscription_plans')
                ->nullOnDelete();
            $table->timestamp('subscribed_at')->nullable()->after('subscription_plan_id');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscribed_at');

            $table->index('subscription_status');
        });

        // Grandfather existing tenants: everything live today becomes "comped"
        // so the gating rollout doesn't lock anyone out. Only stores created
        // after this migration start as "unsubscribed".
        DB::table('stores')
            ->where('subscription_status', 'unsubscribed')
            ->update(['subscription_status' => 'comped']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['subscription_status']);
            $table->dropColumn(['subscription_status', 'subscription_plan_id', 'subscribed_at', 'subscription_expires_at']);
        });
    }
};
