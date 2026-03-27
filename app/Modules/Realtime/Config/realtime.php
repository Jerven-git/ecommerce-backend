<?php

return [
    'enabled' => env('REALTIME_ENABLED', true),

    'version_file' => storage_path('app/version.txt'),

    'channels' => [
        'updates' => 'ssu.updates',
        'admin' => 'private-ssu.admin',
    ],

    'broadcast_models' => [
        \App\Models\Product::class,
        \App\Models\SiteConfig::class,
        \App\Models\Category::class,
        \App\Models\Discount::class,
    ],
];
