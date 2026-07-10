<?php

namespace App\Support\Dns;

/**
 * Resolves a hostname to its public A/AAAA addresses. Behind an interface so
 * domain verification can be tested without touching real DNS.
 */
interface DnsLookup
{
    /**
     * @return list<string> IPv4/IPv6 addresses, empty when the host does not resolve
     */
    public function addressesFor(string $host): array;
}
