<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table): void {
            // Public identifiers (sent to the frontend) — plaintext.
            $table->string('stripe_publishable_key')->nullable()->after('square_enabled');
            $table->string('paypal_client_id')->nullable()->after('stripe_publishable_key');
            $table->string('paypal_mode')->nullable()->after('paypal_client_id');
            $table->string('paypal_webhook_id')->nullable()->after('paypal_mode');
            $table->string('square_application_id')->nullable()->after('paypal_webhook_id');
            $table->string('square_location_id')->nullable()->after('square_application_id');
            $table->string('square_mode')->nullable()->after('square_location_id');

            // Secrets — stored encrypted (Laravel `encrypted` cast). `text` because
            // the ciphertext is much longer than the raw key.
            $table->text('stripe_secret_key')->nullable()->after('square_mode');
            $table->text('stripe_webhook_secret')->nullable()->after('stripe_secret_key');
            $table->text('paypal_secret')->nullable()->after('stripe_webhook_secret');
            $table->text('square_access_token')->nullable()->after('paypal_secret');
            $table->text('square_webhook_secret')->nullable()->after('square_access_token');
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'stripe_publishable_key',
                'paypal_client_id',
                'paypal_mode',
                'paypal_webhook_id',
                'square_application_id',
                'square_location_id',
                'square_mode',
                'stripe_secret_key',
                'stripe_webhook_secret',
                'paypal_secret',
                'square_access_token',
                'square_webhook_secret',
            ]);
        });
    }
};
