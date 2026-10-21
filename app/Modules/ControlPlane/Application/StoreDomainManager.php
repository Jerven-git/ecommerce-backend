<?php

namespace App\Modules\ControlPlane\Application;

use App\Models\Store;
use App\Rules\StoreCustomDomain;
use App\Support\Tenancy\DomainVerifier;
use Illuminate\Support\Facades\Validator;

class StoreDomainManager
{
    public function __construct(private readonly DomainVerifier $verifier) {}

    public function availability(string $value, ?int $storeId): array
    {
        $domain = $this->canonicalise($value);
        if ($domain === '') {
            return [['message' => 'A domain is required.'], 422];
        }

        $validator = Validator::make(['domain' => $domain], ['domain' => [new StoreCustomDomain]]);
        if ($validator->fails()) {
            return [[
                'domain' => $domain, 'valid' => false, 'available' => false,
                'reason' => $validator->errors()->first('domain'), 'claimed_by' => null,
                'dns' => null, 'expected_ips' => $this->verifier->serverIps(),
            ], 200];
        }

        $claimant = Store::where('domain', $domain)
            ->when($storeId, fn ($query) => $query->whereKeyNot($storeId))->first();
        if ($claimant) {
            return [[
                'domain' => $domain, 'valid' => true, 'available' => false,
                'reason' => "Already claimed by the store \"{$claimant->name}\".",
                'claimed_by' => ['id' => $claimant->id, 'name' => $claimant->name],
                'dns' => null, 'expected_ips' => $this->verifier->serverIps(),
            ], 200];
        }

        $dns = $this->verifier->lookup($domain);

        return [[
            'domain' => $domain, 'valid' => true, 'available' => true, 'reason' => null,
            'claimed_by' => null, 'dns' => $dns, 'expected_ips' => $dns['expected_ips'],
        ], 200];
    }

    public function verify(Store $store): array
    {
        if ($store->domain === null) {
            return [['message' => 'This store has no custom domain to verify.'], 422];
        }
        if (! $this->verifier->isConfigured()) {
            return [[
                'message' => 'Domain verification is unavailable: set STOREFRONT_SERVER_IPS to this server\'s public IP addresses.',
            ], 422];
        }

        $result = $this->verifier->verify($store);
        if (! $result['verified']) {
            return [[
                'message' => $result['resolves']
                    ? 'This domain resolves, but not to this server. Update its A record and try again.'
                    : 'This domain does not resolve yet. DNS changes can take a while to propagate.',
                'dns' => $result,
            ], 422];
        }

        return [[
            'message' => 'Domain verified. It is now live and a certificate will be issued on first visit.',
            'dns' => $result,
            'data' => [
                'id' => $store->id, 'domain' => $store->domain,
                'domain_verified_at' => $store->domain_verified_at,
                'domain_verified' => $store->hasVerifiedDomain(),
            ],
        ], 200];
    }

    private function canonicalise(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'www.') && ! str_starts_with(substr($domain, 4), 'www.')
            ? substr($domain, 4)
            : $domain;
    }
}
