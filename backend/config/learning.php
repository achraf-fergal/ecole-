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

        // Short expiration for private asset preview URLs.
        'temporary_url_ttl_minutes' => (int) env(
            'LEARNING_MEDIA_URL_TTL_MINUTES',
            3
        ),

    ],

];
