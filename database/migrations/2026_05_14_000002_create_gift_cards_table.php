<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->decimal('original_amount', 10, 2);
            $table->decimal('balance', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('purchaser_name');
            $table->string('purchaser_email');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email');
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending_payment');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
