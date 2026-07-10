<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom domains become a two-state claim: a super admin may enter any domain,
 * but it only routes traffic and receives a TLS certificate once its DNS has
 * been shown to point at this server.
 *
 * Existing domains are grandfathered as verified — they are already live, and
 * failing them closed here would take those storefronts offline on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoCanonicalCollisions();

        Schema::table('stores', function (Blueprint $table): void {
            $table->timestamp('domain_verified_at')->nullable()->after('domain');
        });

        // Soft-deleted stores never resolve, yet their domain still occupies the
        // unique index and blocks a live store from claiming it. Release those.
        DB::table('stores')
            ->whereNotNull('deleted_at')
            ->whereNotNull('domain')
            ->update(['domain' => null, 'domain_verified_at' => null]);

        // Store the canonical form only: lowercase, no leading "www.". The host
        // resolver canonicalises inbound hosts the same way, so a row saved as
        // "www.example.com" would never match a visit to "example.com".
        $live = DB::table('stores')
            ->whereNull('deleted_at')
            ->whereNotNull('domain')
            ->get(['id', 'domain']);

        foreach ($live as $store) {
            $canonical = strtolower(trim($store->domain));

            if (str_starts_with($canonical, 'www.')) {
                $canonical = substr($canonical, 4);
            }

            DB::table('stores')->where('id', $store->id)->update([
                'domain' => $canonical,
                'domain_verified_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('domain_verified_at');
        });
    }

    /**
     * Canonicalising "www.example.com" to "example.com" would violate the unique
     * index if another live store already holds the bare form. Refuse up front
     * rather than failing halfway through, leaving the column added and the
     * backfill partially applied.
     *
     * `php artisan storefront:audit-domains` reports these before deploy.
     */
    private function assertNoCanonicalCollisions(): void
    {
        $seen = [];

        $rows = DB::table('stores')
            ->whereNull('deleted_at')
            ->whereNotNull('domain')
            ->get(['slug', 'domain']);

        foreach ($rows as $row) {
            $canonical = strtolower(trim($row->domain));

            if (str_starts_with($canonical, 'www.')) {
                $canonical = substr($canonical, 4);
            }

            if (isset($seen[$canonical])) {
                throw new \RuntimeException(
                    "Cannot migrate: stores \"{$seen[$canonical]}\" and \"{$row->slug}\" both resolve to the domain ".
                    "\"{$canonical}\". Clear the duplicate before migrating (see: php artisan storefront:audit-domains)."
                );
            }

            $seen[$canonical] = $row->slug;
        }
    }
};
