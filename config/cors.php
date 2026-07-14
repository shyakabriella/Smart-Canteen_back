<?php

$defaultOrigins = implode(',', [
    // Local development
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:3010',
    'http://127.0.0.1:3010',

    // Production frontend
    'https://canabera.asyncafrica.com',
    'https://www.canabera.asyncafrica.com',

    // API domain
    'https://canteen.asyncafrica.com',
    'https://www.canteen.asyncafrica.com',
]);

$allowedOrigins = array_values(
    array_unique(
        array_filter(
            array_map(
                static fn (string $origin): string => rtrim(trim($origin), '/'),
                explode(
                    ',',
                    (string) env('CORS_ALLOWED_ORIGINS', $defaultOrigins)
                )
            ),
            static fn (string $origin): bool => $origin !== ''
        )
    )
);

return [

    /*
    |--------------------------------------------------------------------------
    | CORS Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    */

    'allowed_methods' => [
        '*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    */

    'allowed_origins' => $allowedOrigins,

    /*
    |--------------------------------------------------------------------------
    | Allowed Origin Patterns
    |--------------------------------------------------------------------------
    */

    'allowed_origins_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    */

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    */

    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Preflight Cache Time
    |--------------------------------------------------------------------------
    */

    'max_age' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Keep false because authentication uses Bearer tokens.
    |
    */

    'supports_credentials' => false,
];