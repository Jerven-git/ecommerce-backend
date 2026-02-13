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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('provider');
            $table->string('provider_ref')->nullable();
            $table->string('status')->default('pending');

            $table->unsignedBigInteger('amount');
            $table->string('currency', 10)->default('USD');
            $table->string('public_token', 64)->nullable()->unique();
            
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_ref']);
            $table->index(['order_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
