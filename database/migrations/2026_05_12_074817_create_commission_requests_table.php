<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->string('title');
            $table->text('description');
            $table->string('budget_range')->nullable();
            $table->string('preferred_medium')->nullable();
            $table->string('preferred_size')->nullable();
            $table->date('deadline')->nullable();
            $table->string('reference_image_url')->nullable();
            $table->string('status')->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_requests');
    }
};
