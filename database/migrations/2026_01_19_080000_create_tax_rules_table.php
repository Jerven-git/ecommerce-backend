<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('region_type', ['all', 'country', 'state']);
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_name')->default('Tax');
            $table->enum('tax_display_mode', ['inclusive', 'exclusive'])->default('exclusive');
            $table->boolean('enabled')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'priority']);
            $table->index(['country', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
