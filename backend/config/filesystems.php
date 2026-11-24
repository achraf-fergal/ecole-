<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    */

    'disks' => [

        // Local application storage.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Private storage dedicated to protected lesson assets.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),

            // A unique URL is required for each served local disk.
            'url' => rtrim(
                env('APP_URL', 'http://localhost:8000'),
                '/'
            ).'/private-storage',

            'serve' => true,
            'throw' => true,
            'report' => true,
        ],

        // Public assets only.
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(
                env('APP_URL', 'http://localhost:8000'),
                '/'
            ).'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Private cloud object storage.
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env(
                'AWS_USE_PATH_STYLE_ENDPOINT',
                false
            ),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
