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
        Schema::create('subscription_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider');
            $table->string('event_id');
            $table->string('event_type')->nullable();

            $table->json('payload');
            $table->timestamps();

            // Stripe can retry delivery; the webhook controller uses this to
            // process each event exactly once.
            $table->unique(['provider', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_webhook_events');
    }
};
