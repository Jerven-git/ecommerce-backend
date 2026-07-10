<?php

namespace Tests\Support;

use App\Support\Dns\DnsLookup;

class FakeDnsLookup implements DnsLookup
{
    /** @param array<string, list<string>> $records */
    public function __construct(private array $records = []) {}

    /** @param list<string> $addresses */
    public function set(string $host, array $addresses): void
    {
        $this->records[$host] = $addresses;
    }

    /** @return list<string> */
    public function addressesFor(string $host): array
    {
        return $this->records[strtolower($host)] ?? [];
    }
}
