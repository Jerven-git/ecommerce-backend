<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Per-store storefronts live on subdomains (acme.localhost, watch.localhost)
    | and the SPA authenticates with cookies (credentials: 'include'). Credentialed
    | requests cannot use a wildcard `*` origin — the response must echo the exact
    | Origin and set Access-Control-Allow-Credentials: true. We therefore match
    | origins by pattern so any store subdomain is allowed without an explicit list.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Bare hosts (no store subdomain) for local dev.
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
    ],

    'allowed_origins_patterns' => [
        // Any *.localhost subdomain on any port (dev storefronts).
        '#^https?://[a-z0-9-]+\.localhost(:\d+)?$#',

        // Prod: uncomment and set your apex domain.
        // '#^https://([a-z0-9-]+\.)?yourdomain\.com$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
