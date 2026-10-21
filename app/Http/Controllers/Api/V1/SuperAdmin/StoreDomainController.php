<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Modules\ControlPlane\Application\StoreDomainManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function __construct(private readonly StoreDomainManager $domains) {}

    /**
     * Pre-flight check for the domain input. Never mutates anything.
     */
    public function check(Request $request): JsonResponse
    {
        [$body, $status] = $this->domains->availability(
            (string) $request->query('domain', ''),
            $request->filled('store_id') ? (int) $request->query('store_id') : null,
        );

        return response()->json($body, $status);
    }

    /**
     * Re-check the store's domain against DNS and flip its verified state.
     */
    public function verify(Store $store): JsonResponse
    {
        [$body, $status] = $this->domains->verify($store);

        return response()->json($body, $status);
    }
}
