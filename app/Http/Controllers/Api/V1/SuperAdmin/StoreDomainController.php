<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Rules\StoreCustomDomain;
use App\Support\Tenancy\DomainVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Domain availability and ownership checks for the super-admin UI.
 *
 * These answer three different questions that are easy to conflate:
 *
 *   valid     — is it a well-formed domain we're allowed to accept at all?
 *   available — is it unclaimed by another store on this platform?
 *   verified  — does its DNS point here, proving the claimant controls it?
 *
 * Only the last is a security control. The first two are guard rails.
 */
class StoreDomainController extends Controller
{
    public function __construct(private DomainVerifier $verifier) {}

    /**
     * Pre-flight check for the domain input. Never mutates anything.
     */
    public function check(Request $request): JsonResponse
    {
        $domain = $this->canonicalise((string) $request->query('domain', ''));
        $storeId = $request->query('store_id');

        if ($domain === '') {
            return response()->json([
                'message' => 'A domain is required.',
            ], 422);
        }

        $validator = Validator::make(
            ['domain' => $domain],
            ['domain' => [new StoreCustomDomain]]
        );

        if ($validator->fails()) {
            return response()->json([
                'domain' => $domain,
                'valid' => false,
                'available' => false,
                'reason' => $validator->errors()->first('domain'),
                'claimed_by' => null,
                'dns' => null,
                'expected_ips' => $this->verifier->serverIps(),
            ]);
        }

        $claimant = Store::query()
            ->where('domain', $domain)
            ->when($storeId, fn ($query) => $query->whereKeyNot($storeId))
            ->first();

        if ($claimant) {
            return response()->json([
                'domain' => $domain,
                'valid' => true,
                'available' => false,
                'reason' => "Already claimed by the store \"{$claimant->name}\".",
                'claimed_by' => ['id' => $claimant->id, 'name' => $claimant->name],
                'dns' => null,
                'expected_ips' => $this->verifier->serverIps(),
            ]);
        }

        // A domain that doesn't resolve yet is still available — it just isn't
        // verifiable until DNS is pointed here. Report both facts separately so
        // the UI can distinguish "you can't have this" from "not pointed yet".
        $dns = $this->verifier->lookup($domain);

        return response()->json([
            'domain' => $domain,
            'valid' => true,
            'available' => true,
            'reason' => null,
            'claimed_by' => null,
            'dns' => $dns,
            'expected_ips' => $dns['expected_ips'],
        ]);
    }

    /**
     * Re-check the store's domain against DNS and flip its verified state.
     */
    public function verify(Store $store): JsonResponse
    {
        if ($store->domain === null) {
            return response()->json([
                'message' => 'This store has no custom domain to verify.',
            ], 422);
        }

        if (! $this->verifier->isConfigured()) {
            return response()->json([
                'message' => 'Domain verification is unavailable: set STOREFRONT_SERVER_IPS to this server\'s public IP addresses.',
            ], 422);
        }

        $result = $this->verifier->verify($store);

        if (! $result['verified']) {
            return response()->json([
                'message' => $result['resolves']
                    ? 'This domain resolves, but not to this server. Update its A record and try again.'
                    : 'This domain does not resolve yet. DNS changes can take a while to propagate.',
                'dns' => $result,
            ], 422);
        }

        return response()->json([
            'message' => 'Domain verified. It is now live and a certificate will be issued on first visit.',
            'dns' => $result,
            'data' => [
                'id' => $store->id,
                'domain' => $store->domain,
                'domain_verified_at' => $store->domain_verified_at,
                'domain_verified' => $store->hasVerifiedDomain(),
            ],
        ]);
    }

    private function canonicalise(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if (str_starts_with($domain, 'www.') && ! str_starts_with(substr($domain, 4), 'www.')) {
            return substr($domain, 4);
        }

        return $domain;
    }
}
