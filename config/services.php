<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'stormglass' => [
        'key' => env('STORMGLASS_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload malware scanning
    |--------------------------------------------------------------------------
    |
    | Certificates are the only user-supplied files the platform accepts. When
    | enabled, each upload is piped through ClamAV; a missing scanner fails the
    | upload rather than letting it through unscanned.
    |
    */
    'virus_scan' => [
        'enabled' => env('VIRUS_SCAN_ENABLED', false),
        'binary' => env('VIRUS_SCAN_BINARY', 'clamdscan'),
    ],

];
