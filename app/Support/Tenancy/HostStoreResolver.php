<?php

namespace App\Support\Tenancy;

use App\Models\Store;

/**
 * Shared host → store logic used by both the storefront middleware (to scope a
 * request) and the ACME TLS-check endpoint (to decide whether Caddy may issue a
 * certificate for an incoming hostname). Keeping it in one place ensures the
 * cert gate and the request router agree on exactly which hosts are "real".
 */
class HostStoreResolver
{
    /**
     * Lowercase/trim a host and strip a single leading "www." so that
     * www.example.com and example.com resolve to the same store and share one
     * certificate decision. Keeps the cert gate and the request router in sync
     * on the www-vs-bare question.
     */
    public function canonicalHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }

    /**
     * Derive a store slug from a subdomain of the configured base domain, or
     * null when the host is the base domain itself or doesn't sit under it.
     */
    public function extractSlug(string $host, string $baseDomain): ?string
    {
        if ($baseDomain === '' || $host === $baseDomain) {
            return null;
        }

        $suffix = '.'.$baseDomain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        return $slug === '' ? null : $slug;
    }

    /**
     * Whether a TLS certificate should be issued on-demand for the given host.
     * True for: the main app domain (and its www), an active store's custom
     * domain, or an active store's subdomain. Everything else is rejected so a
     * stranger can't force unlimited cert issuance by pointing junk domains at
     * the server.
     */
    public function isIssuableHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        $canonical = $this->canonicalHost($host);
        $baseDomain = strtolower((string) config('storefront.base_domain'));

        if ($baseDomain !== '' && $canonical === $baseDomain) {
            return true;
        }

        $matchesStoreDomain = Store::query()
            ->where('status', 'active')
            ->where(function ($query) use ($host, $canonical) {
                $query->where('domain', $host)->orWhere('domain', $canonical);
            })
            ->exists();

        if ($matchesStoreDomain) {
            return true;
        }

        $slug = $this->extractSlug($canonical, $baseDomain);

        if ($slug !== null && Store::query()->where('slug', $slug)->where('status', 'active')->exists()) {
            return true;
        }

        return false;
    }
}
