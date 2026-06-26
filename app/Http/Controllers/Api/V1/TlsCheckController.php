<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\HostStoreResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ACME "ask" endpoint for Caddy's on-demand TLS. Before Caddy issues a
 * certificate for an incoming hostname it calls this with `?domain=<host>`;
 * a 2xx means "issue it", anything else means "refuse". This gates issuance to
 * hosts that actually belong to the platform (the app domain or an active
 * store's domain/subdomain), preventing strangers from forcing unlimited cert
 * requests by pointing arbitrary domains at the server.
 */
class TlsCheckController extends Controller
{
    public function __construct(private HostStoreResolver $resolver) {}

    public function __invoke(Request $request): Response
    {
        $domain = (string) $request->query('domain', '');

        if ($this->resolver->isIssuableHost($domain)) {
            return response('ok');
        }

        return response('unknown host', Response::HTTP_FORBIDDEN);
    }
}
