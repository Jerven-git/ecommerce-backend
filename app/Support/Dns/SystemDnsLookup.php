<?php

namespace App\Support\Dns;

class SystemDnsLookup implements DnsLookup
{
    /**
     * @return list<string>
     */
    public function addressesFor(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = strtolower($address);
            }
        }

        return array_values(array_unique($addresses));
    }
}
