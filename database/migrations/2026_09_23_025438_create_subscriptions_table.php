<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')
                ->nullable()
                ->constrained('subscription_plans')
                ->nullOnDelete();

            $table->string('provider', 32)->nullable();
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_checkout_session_id')->nullable();

            $table->string('status', 20);
            $table->string('mode', 20)->default('initial');

            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['provider', 'provider_subscription_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
