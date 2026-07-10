<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\HostStoreResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Canonical-host gate for nginx's `auth_request` (host-agnostic; called by
 * nginx on the internal network, never by browsers).
 *
 * The storefront is a client-rendered SPA served as static files, so a page
 * load never reaches Laravel — which means Laravel cannot issue the redirect
 * itself. Instead nginx makes an internal subrequest here for each HTML
 * request and we answer with the host the visitor *should* be on. nginx turns
 * that into a real 301. This mirrors the TlsCheckController pattern: the
 * decision lives in HostStoreResolver so the router, the certificate gate and
 * the redirect can never disagree.
 *
 * Responses:
 *   204 — stay put, nginx serves the page.
 *   401 — redirect, target in the X-Canonical-Redirect header.
 *
 * The 401 is not an authorisation failure; it is how nginx is told to act. Its
 * `if` directive runs in the rewrite phase, *before* auth_request runs in the
 * access phase, so a variable set from the subrequest is still empty by then.
 * A non-2xx lets nginx catch the result with `error_page 401 = @…`, which does
 * run late enough to read the header. Verified against a live nginx.
 */
class CanonicalHostController extends Controller
{
    /**
     * Paths that must never be redirected off the host they were requested on.
     *
     * Admin sign-in is deliberately host-agnostic, and the session cookie is
     * scoped to the platform's base domain — bouncing an admin onto a store's
     * custom domain would silently drop their session.
     */
    private const EXEMPT_PREFIXES = ['/api', '/storage', '/app', '/admin', '/super-admin'];

    public function __construct(private HostStoreResolver $resolver) {}

    public function __invoke(Request $request): Response
    {
        if ($this->isExempt($this->requestedPath($request))) {
            return response()->noContent();
        }

        $target = $this->resolver->redirectTargetFor((string) $request->query('host', ''));

        if ($target === null) {
            return response()->noContent();
        }

        $scheme = $request->isSecure() ? 'https' : 'http';

        return response()->noContent(Response::HTTP_UNAUTHORIZED)
            ->header('X-Canonical-Redirect', $scheme.'://'.$target);
    }

    /**
     * nginx forwards the original request line here, since this subrequest's
     * own URI is the internal endpoint rather than the page being visited.
     */
    private function requestedPath(Request $request): string
    {
        $original = $request->header('X-Original-URI', '/');
        $path = parse_url($original, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function isExempt(string $path): bool
    {
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
