<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storefront Base Domain
    |--------------------------------------------------------------------------
    |
    | The shared apex domain that store subdomains sit under. The
    | ResolveStorefrontStore middleware strips this suffix from the incoming
    | Host header to derive the store slug.
    |
    | Examples:
    |   acme.localhost          (base_domain=localhost)   -> slug "acme"
    |   acme.yoursite.com       (base_domain=yoursite.com) -> slug "acme"
    |   localhost / yoursite.com (bare host)              -> default store
    |
    */

    'base_domain' => env('STOREFRONT_BASE_DOMAIN', 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Default Store Slug
    |--------------------------------------------------------------------------
    |
    | The store used when the incoming Host header matches the base domain
    | exactly (no subdomain). Must match a row in the `stores` table.
    |
    */

    'default_store_slug' => env('STOREFRONT_DEFAULT_STORE_SLUG', 'default'),

];
