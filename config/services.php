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

    'turnstile' => [
        'site_key' => env(
            'TURNSTILE_SITE_KEY',
            in_array(env('APP_ENV'), ['local', 'testing'], true)
                ? '1x00000000000000000000AA'
                : null,
        ),
        'secret_key' => env(
            'TURNSTILE_SECRET_KEY',
            in_array(env('APP_ENV'), ['local', 'testing'], true)
                ? '1x0000000000000000000000000000000AA'
                : null,
        ),
    ],

    'max' => [
        'enabled' => (bool) env('MAX_ENABLED', false),
        'bot_id' => env('MAX_BOT_ID'),
        'username' => env('MAX_BOT_USERNAME'),
        'token' => env('MAX_BOT_TOKEN'),
        'webhook_secret' => env('MAX_WEBHOOK_SECRET'),
        'ca_bundle' => env('MAX_CA_BUNDLE'),
    ],

    'telegram' => [
        'enabled' => (bool) env('TELEGRAM_ENABLED', false),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'proxy' => env('TELEGRAM_PROXY_URL'),
    ],
];
