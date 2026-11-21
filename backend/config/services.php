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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost:8000').'/api/auth/google/callback'),
    ],
    'cloudflare_stream' => [
        'account_id' => env('CLOUDFLARE_STREAM_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_STREAM_API_TOKEN'),
        'customer_code' => env('CLOUDFLARE_STREAM_CUSTOMER_CODE'),

        'allowed_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'CLOUDFLARE_STREAM_ALLOWED_ORIGINS',
                'http://localhost:3000'
            ))
        ))),

        'max_duration_seconds' => (int) env(
            'CLOUDFLARE_STREAM_MAX_DURATION_SECONDS',
            3600
        ),

        'upload_expiry_minutes' => (int) env(
            'CLOUDFLARE_STREAM_UPLOAD_EXPIRY_MINUTES',
            30
        ),

        'max_upload_bytes' => (int) env(
            'CLOUDFLARE_STREAM_MAX_UPLOAD_BYTES',
            5368709120
        ),

        // Cloudflare's default per-video limit is less than 30 GB.
        // Raise this only when the account's higher limit is confirmed.
        'account_max_upload_bytes' => (int) env(
            'CLOUDFLARE_STREAM_ACCOUNT_MAX_UPLOAD_BYTES',
            29999999999
        ),

        'tus_chunk_size_bytes' => (int) env(
            'CLOUDFLARE_STREAM_TUS_CHUNK_SIZE_BYTES',
            52428800
        ),

        'webhook_secret' => env('CLOUDFLARE_STREAM_WEBHOOK_SECRET'),

        'webhook_tolerance_seconds' => (int) env(
            'CLOUDFLARE_STREAM_WEBHOOK_TOLERANCE_SECONDS',
            300
        ),

        'token_ttl_minutes' => (int) env(
            'CLOUDFLARE_STREAM_TOKEN_TTL_MINUTES',
            10
        ),
    ],
];
