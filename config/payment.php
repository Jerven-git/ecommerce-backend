<?php

return [
    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key'      => env('STRIPE_SECRET_KEY'),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret'    => env('PAYPAL_SECRET'),
        'mode'      => env('PAYPAL_MODE', 'sandbox'),
        'webhook_id'=> env('PAYPAL_WEBHOOK_ID'),
    ],

    'square' => [
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'access_token'   => env('SQUARE_ACCESS_TOKEN'),
        'location_id'    => env('SQUARE_LOCATION_ID'),
        'webhook_secret' => env('SQUARE_WEBHOOK_SECRET'),
        'webhook_url'    => env('SQUARE_WEBHOOK_URL'),
        'mode'           => env('SQUARE_MODE', 'sandbox'),
    ],
];
