<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropUnique('subscribers_email_unique');
            $table->unique(['store_id', 'email'], 'subscribers_store_id_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropUnique('subscribers_store_id_email_unique');
            $table->unique('email', 'subscribers_email_unique');
        });
    }
};
