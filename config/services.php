<?php

/*
 * FILE PURPOSE
 * File   : config/services.php
 * Feature: Config
 * Purpose: Keys and settings for outside services: Gemini, ID Analyzer / DocuPass, ID recognition,
 *          Semaphore SMS, WhatsApp, Chronos.
 */

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

    // WhatsApp Cloud API (app/Services/WhatsAppIntegration.php). Tried first for client mobile notifications;
    // SMS is the backup when WhatsApp is off or fails. WHATSAPP_DRIVER: off | log | meta.
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'off'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v25.0'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        // Approved message template. hello_world (Meta's sample) has no variables;
        // set WHATSAPP_TEMPLATE_HAS_MESSAGE=true for your own template whose body has one {{1}} for the update text.
        'template' => env('WHATSAPP_TEMPLATE', 'hello_world'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en_US'),
        'template_has_message' => (bool) env('WHATSAPP_TEMPLATE_HAS_MESSAGE', false),
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

    // ID recognition for client onboarding (app/Services/Identity).
    'identity' => [
        // Which IdDocumentReader reads the ID image: "idanalyzer" (ID Analyzer API) or "gemini".
        'reader' => env('IDENTITY_READER', 'gemini'),
        // How clients verify: "docupass" = live ID + selfie on ID Analyzer's hosted page (photo upload stays as a backup),
        // "upload" = photo upload only.
        'method' => env('IDENTITY_METHOD', 'upload'),
        // Fuzzy name match (0-1) between the name on the ID and the account / company contact name.
        'name_match_threshold' => (float) env('IDENTITY_NAME_MATCH', 0.85),
        // Below this reader confidence (0-1) the ID goes to admin review instead of passing automatically.
        'min_confidence' => (float) env('IDENTITY_MIN_CONFIDENCE', 0.7),
        'max_attempts_per_day' => (int) env('IDENTITY_MAX_ATTEMPTS', 5),
        // Days after a decision before the ID image is deleted (the decision record is kept).
        'retention_days' => (int) env('IDENTITY_RETENTION_DAYS', 90),
    ],

    // ID Analyzer v2 (https://developer.idanalyzer.com), used when IDENTITY_READER=idanalyzer.
    'idanalyzer' => [
        'api_key' => env('IDANALYZER_API_KEY'),
        // A preset (security_low / security_medium / security_high) or your KYC profile ID from the portal.
        'profile' => env('IDANALYZER_PROFILE', 'security_medium'),
        // US region by default; EU data residency: https://api2-eu.idanalyzer.com
        'base_url' => env('IDANALYZER_BASE_URL', 'https://api2.idanalyzer.com'),
        // Signing secret for webhooks (Profile > General in the portal); webhooks are refused without it.
        'webhook_secret' => env('IDANALYZER_WEBHOOK_SECRET'),
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
