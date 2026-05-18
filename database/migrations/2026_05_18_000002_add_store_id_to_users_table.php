<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('store_id')
                ->nullable()
                ->after('is_admin')
                ->constrained('stores')
                ->restrictOnDelete();
        });

        $defaultStoreId = DB::table('stores')->where('slug', 'default')->value('id');

        if ($defaultStoreId === null) {
            return;
        }

        $superAdminIds = DB::table('users')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.name', 'super_admin')
            ->pluck('users.id');

        DB::table('users')
            ->when($superAdminIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $superAdminIds))
            ->update(['store_id' => $defaultStoreId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
