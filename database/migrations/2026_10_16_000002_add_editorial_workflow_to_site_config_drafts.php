<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_config_drafts', function (Blueprint $table) {
            $table->string('review_status', 32)->default('draft')->after('lock_version')->index();
            $table->foreignId('review_requested_by')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('review_requested_at')->nullable()->after('review_requested_by');
            $table->foreignId('reviewed_by')->nullable()->after('review_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->timestamp('scheduled_publish_at')->nullable()->after('review_note')->index();
            $table->foreignId('scheduled_by')->nullable()->after('scheduled_publish_at')->constrained('users')->nullOnDelete();
            $table->string('scheduled_release_note', 500)->nullable()->after('scheduled_by');
        });
    }

    public function down(): void
    {
        Schema::table('site_config_drafts', function (Blueprint $table) {
            $table->dropForeign(['review_requested_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['scheduled_by']);
            $table->dropIndex(['review_status']);
            $table->dropIndex(['scheduled_publish_at']);
            $table->dropColumn([
                'review_status',
                'review_requested_by',
                'review_requested_at',
                'reviewed_by',
                'reviewed_at',
                'review_note',
                'scheduled_publish_at',
                'scheduled_by',
                'scheduled_release_note',
            ]);
        });
    }
};
