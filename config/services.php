<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Demo-only KHQR (Bakong) checkout. Nothing is charged; the account below is made up and cannot receive money.
    'khqr' => [
        'account_id' => env('KHQR_ACCOUNT_ID', 'khmer_shop_demo@demo'),
        'merchant_name' => env('KHQR_MERCHANT_NAME', 'Khmer Shop'),
        'merchant_city' => env('KHQR_MERCHANT_CITY', 'Phnom Penh'),
        // Seconds a code can be paid before it expires.
        'lifetime' => (int) env('KHQR_LIFETIME', 180),
        // Allows the "Simulate payment" button. Off in production unless set.
        'simulate' => (bool) env('KHQR_SIMULATE', env('APP_ENV') !== 'production'),
    ],

];
