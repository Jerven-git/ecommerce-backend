<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default SMS Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "twilio", "vonage", "log", "null"
    |
    */

    'default' => env('SMS_PROVIDER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | SMS Provider Configurations
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM_NUMBER'),
        ],

        'vonage' => [
            'key' => env('VONAGE_API_KEY'),
            'secret' => env('VONAGE_API_SECRET'),
            'from' => env('VONAGE_FROM_NUMBER'),
        ],

        'log' => [
            'channel' => env('SMS_LOG_CHANNEL', 'stack'),
        ],

        'null' => [],

    ],

];
