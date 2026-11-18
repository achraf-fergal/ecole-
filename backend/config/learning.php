<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Learning Media
    |--------------------------------------------------------------------------
    */

    'media' => [

        // Only private storage disks may serve protected learning assets.
        'allowed_disks' => array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string) env(
                            'LEARNING_MEDIA_ALLOWED_DISKS',
                            'private,s3'
                        )
                    )
                )
            )
        ),

        // All uploaded lesson files are stored on this server-selected disk.
        'upload_disk' => env(
            'LEARNING_MEDIA_UPLOAD_DISK',
            'private'
        ),

        // Maximum image upload size in kilobytes.
        'max_image_kb' => (int) env(
            'LEARNING_MEDIA_MAX_IMAGE_KB',
            10240
        ),

        // Maximum document upload size in kilobytes.
        'max_file_kb' => (int) env(
            'LEARNING_MEDIA_MAX_FILE_KB',
            25600
        ),

        // Short expiration for private asset preview URLs.
        'temporary_url_ttl_minutes' => (int) env(
            'LEARNING_MEDIA_URL_TTL_MINUTES',
            3
        ),

    ],

];
