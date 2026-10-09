<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stores')
            ->whereNull('deleted_at')
            // The platform fallback store predates tenant provisioning and
            // deliberately retains its lazy SiteConfig creation path.
            ->where('slug', '!=', 'default')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('site_config')
                    ->whereColumn('site_config.store_id', 'stores.id');
            })
            ->orderBy('id')
            ->pluck('id')
            ->chunk(500)
            ->each(function ($storeIds): void {
                $now = now();
                $rows = $storeIds->map(fn ($storeId): array => [
                    'store_id' => $storeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('site_config')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        // Existing tenant configuration is business data and must not be removed.
    }
};
