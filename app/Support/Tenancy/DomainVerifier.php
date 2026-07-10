<?php

namespace App\Support\Tenancy;

use App\Models\Store;
use App\Support\Dns\DnsLookup;

/**
 * Proves that whoever claims a custom domain actually controls it, by checking
 * that the domain's public DNS points at this server. Pointing an A record at
 * us requires control of the domain's DNS, so this doubles as the ownership
 * check — the same bar Vercel and Netlify use.
 *
 * Until a domain verifies, ResolveStorefrontStore ignores it and the ACME
 * gate refuses to issue a certificate for it, so an unverified (or squatted,
 * or typo'd) claim can neither serve traffic nor burn Let's Encrypt quota.
 */
class DomainVerifier
{
    public function __construct(private DnsLookup $dns) {}

    /**
     * The A/AAAA targets a customer must point their domain at.
     *
     * @return list<string>
     */
    public function serverIps(): array
    {
        /** @var list<string> $ips */
        $ips = config('storefront.server_ips', []);

        return array_values(array_map('strtolower', $ips));
    }

    /**
     * Verification is impossible without knowing our own public address. Fail
     * closed and say so rather than waving the domain through.
     */
    public function isConfigured(): bool
    {
        return $this->serverIps() !== [];
    }

    /**
     * Resolve a domain and report whether it currently points at this server.
     * Both the bare host and its "www." alias are inspected so a customer who
     * only pointed one of them still gets a useful answer.
     *
     * @return array{resolves: bool, addresses: list<string>, points_at_server: bool, expected_ips: list<string>}
     */
    public function lookup(string $domain): array
    {
        $addresses = $this->dns->addressesFor($domain);
        $serverIps = $this->serverIps();

        return [
            'resolves' => $addresses !== [],
            'addresses' => $addresses,
            'points_at_server' => $serverIps !== [] && array_intersect($addresses, $serverIps) !== [],
            'expected_ips' => $serverIps,
        ];
    }

    /**
     * Mark the store's domain verified when DNS backs the claim, and clear any
     * prior verification when it no longer does — a domain that stopped
     * pointing at us has effectively been handed back.
     *
     * @return array{verified: bool, resolves: bool, addresses: list<string>, points_at_server: bool, expected_ips: list<string>}
     */
    public function verify(Store $store): array
    {
        $result = $this->lookup((string) $store->domain);

        $store->forceFill([
            'domain_verified_at' => $result['points_at_server'] ? now() : null,
        ])->save();

        return ['verified' => $result['points_at_server']] + $result;
    }
}
