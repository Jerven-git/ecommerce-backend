<?php

namespace App\Support\Tenancy;

use App\Models\Store;

/**
 * Shared host → store logic used by both the storefront middleware (to scope a
 * request) and the ACME TLS-check endpoint (to decide whether Caddy may issue a
 * certificate for an incoming hostname). Keeping it in one place ensures the
 * cert gate and the request router agree on exactly which hosts are "real".
 *
 * Hosts split into two disjoint namespaces, and never cross over:
 *
 *   - Platform hosts (the base domain and everything under it) resolve by
 *     slug only. `stores.domain` is never consulted for them, so a stray or
 *     malicious custom-domain row cannot shadow another store's subdomain or
 *     the admin apex.
 *   - Everything else resolves via `stores.domain`, and only once that domain
 *     has been DNS-verified.
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

    public function baseDomain(): string
    {
        return strtolower(trim((string) config('storefront.base_domain')));
    }

    /**
     * Whether a canonical host belongs to the platform's own domain — the base
     * domain itself or any subdomain of it.
     */
    public function isPlatformHost(string $canonicalHost): bool
    {
        $baseDomain = $this->baseDomain();

        if ($baseDomain === '') {
            return false;
        }

        return $canonicalHost === $baseDomain
            || str_ends_with($canonicalHost, '.'.$baseDomain);
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
     * Find the active store that owns a verified custom domain. Unverified
     * claims resolve to nothing, so a squatted or typo'd domain neither serves
     * traffic nor earns a certificate.
     */
    public function storeForCustomDomain(string $canonicalHost): ?Store
    {
        if ($canonicalHost === '' || $this->isPlatformHost($canonicalHost)) {
            return null;
        }

        return Store::query()
            ->whereNotNull('domain_verified_at')
            ->where('domain', $canonicalHost)
            ->first();
    }

    /**
     * The single hostname a store should be reachable on. Every other host that
     * resolves to the same store is a duplicate that ought to redirect here.
     *
     *   acme.example.com          -> jervenstore.com   (verified custom domain)
     *   acme.example.com          -> acme.example.com  (no custom domain yet)
     *   www.jervenstore.com       -> jervenstore.com
     *   www.example.com           -> example.com       (the admin apex)
     *   somethingunknown.com      -> null              (bound to no store)
     *
     * Returns null when the host resolves to no active store, so unknown hosts
     * are left alone rather than bounced somewhere arbitrary.
     */
    public function canonicalHostFor(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        $canonical = $this->canonicalHost($host);
        $baseDomain = $this->baseDomain();

        if ($this->isPlatformHost($canonical)) {
            if ($canonical === $baseDomain) {
                return $baseDomain;
            }

            $slug = $this->extractSlug($canonical, $baseDomain);

            if ($slug === null) {
                return null;
            }

            $store = Store::query()->where('slug', $slug)->where('status', 'active')->first();

            if (! $store) {
                return null;
            }

            // A store only moves to its custom domain once that domain is
            // verified; until then the subdomain remains its canonical home.
            return $store->hasVerifiedDomain() ? $store->domain : $canonical;
        }

        $store = $this->storeForCustomDomain($canonical);

        return $store && $store->isActive() ? $canonical : null;
    }

    /**
     * The host to 301 to, or null when the visitor is already canonical.
     */
    public function redirectTargetFor(string $host): ?string
    {
        $host = strtolower(trim($host));
        $target = $this->canonicalHostFor($host);

        return $target !== null && $target !== $host ? $target : null;
    }

    /**
     * Whether a TLS certificate should be issued on-demand for the given host.
     * True for: the main app domain (and its www), an active store's verified
     * custom domain, or an active store's subdomain. Everything else is
     * rejected so a stranger can't force unlimited cert issuance by pointing
     * junk domains at the server.
     */
    public function isIssuableHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        $canonical = $this->canonicalHost($host);
        $baseDomain = $this->baseDomain();

        if ($this->isPlatformHost($canonical)) {
            if ($canonical === $baseDomain) {
                return true;
            }

            $slug = $this->extractSlug($canonical, $baseDomain);

            return $slug !== null
                && Store::query()->where('slug', $slug)->where('status', 'active')->exists();
        }

        return Store::query()
            ->where('status', 'active')
            ->whereNotNull('domain_verified_at')
            ->where('domain', $canonical)
            ->exists();
    }
}
