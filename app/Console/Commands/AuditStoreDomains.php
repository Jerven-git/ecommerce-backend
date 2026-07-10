<?php

namespace App\Console\Commands;

use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-flight check before deploying custom-domain verification to a live
 * environment. Reports rows that the stricter rules would treat differently
 * than the old ones, so nothing goes dark unnoticed on deploy.
 *
 * Safe to run at any time; reads only.
 */
class AuditStoreDomains extends Command
{
    protected $signature = 'storefront:audit-domains';

    protected $description = 'Report store domains that collide with the platform base domain or with each other';

    public function handle(): int
    {
        $baseDomain = strtolower(trim((string) config('storefront.base_domain')));

        if ($baseDomain === '') {
            $this->warn('storefront.base_domain is empty; subdomain collisions cannot be checked.');
        }

        $this->line('Base domain: <info>'.($baseDomain ?: '(unset)').'</info>');
        $this->newLine();

        $rows = DB::table('stores')->whereNotNull('domain')->get(['id', 'slug', 'name', 'domain', 'deleted_at', 'status']);

        $reserved = [];
        $collisions = [];
        $trashed = [];
        $canonicalised = [];
        $seen = [];

        foreach ($rows as $row) {
            $domain = strtolower(trim((string) $row->domain));
            $canonical = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

            if ($row->deleted_at !== null) {
                $trashed[] = $row;

                continue;
            }

            if ($canonical !== $row->domain) {
                $canonicalised[] = [$row->slug, $row->domain, $canonical];
            }

            if ($baseDomain !== '' && ($canonical === $baseDomain || str_ends_with($canonical, '.'.$baseDomain))) {
                $reserved[] = [$row->id, $row->slug, $row->domain, $row->status];
            }

            if (isset($seen[$canonical])) {
                $collisions[] = [$canonical, $seen[$canonical], $row->slug];
            }

            $seen[$canonical] = $row->slug;
        }

        $problems = 0;

        if ($collisions !== []) {
            $problems++;
            $this->error('BLOCKING — two live stores canonicalise to the same domain. The migration would hit a duplicate-key error:');
            $this->table(['domain', 'store A', 'store B'], $collisions);
            $this->line('Fix: clear the duplicate from one store before migrating.');
            $this->newLine();
        }

        if ($reserved !== []) {
            $problems++;
            $this->error('BLOCKING — these stores claim the base domain or a subdomain of it. After deploy the router ignores stores.domain for those hosts, so these storefronts will stop resolving via their custom domain:');
            $this->table(['id', 'slug', 'domain', 'status'], $reserved);
            $this->line('Each store already answers on <info>{slug}.'.$baseDomain.'</info>. Clear the domain column on these rows, or move them to a real external domain, before deploying.');
            $this->newLine();
        }

        if ($canonicalised !== []) {
            $this->warn('These domains will be rewritten to their canonical form (this is a fix — the "www." form never matched an inbound request):');
            $this->table(['slug', 'stored now', 'after migration'], $canonicalised);
            $this->newLine();
        }

        if ($trashed !== []) {
            $this->warn(count($trashed).' soft-deleted store(s) still hold a domain; the migration releases it so another store can claim it.');
            $this->newLine();
        }

        $live = Store::query()->whereNotNull('domain')->count();
        $this->line("Live stores with a custom domain: <info>{$live}</info> — all will be grandfathered as verified, so none go offline.");

        if ($problems > 0) {
            $this->newLine();
            $this->error('Resolve the BLOCKING items above before running the migration.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('No blocking issues. Safe to migrate.');

        return self::SUCCESS;
    }
}
