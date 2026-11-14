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

    // SMS notifications (app/Services/SmsIntegration.php). SMS_DRIVER=log writes SMS to the log instead of sending.
    'semaphore' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
        'url' => env('SEMAPHORE_URL', 'https://api.semaphore.co/api/v4/messages'),
        'timeout' => 10,
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Local Amazon Chronos forecasting service (forecasting/chronos_service.py).
    'chronos' => [
        'url' => env('CHRONOS_URL', 'http://127.0.0.1:8001'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        // CA bundle for HTTPS to Google when PHP's curl.cainfo is not set (cURL error 60 on Windows PHP).
        // Defaults to the bundle XAMPP ships, which the prototype used through XAMPP's php.ini.
        'ca_bundle' => env('GEMINI_CA_BUNDLE', is_file('C:\xampp\apache\bin\curl-ca-bundle.crt') ? 'C:\xampp\apache\bin\curl-ca-bundle.crt' : null),
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

];
