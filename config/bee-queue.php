<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Name
    |--------------------------------------------------------------------------
    */
    'default' => env('BEE_QUEUE_DEFAULT', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    | The Redis connection name from config/database.php to use.
    */
    'redis_connection' => env('BEE_QUEUE_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Key Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env('BEE_QUEUE_PREFIX', 'bq'),

    /*
    |--------------------------------------------------------------------------
    | Worker Settings
    |--------------------------------------------------------------------------
    */
    'worker' => [
        'concurrency'       => env('BEE_QUEUE_CONCURRENCY', 1),
        'timeout'           => env('BEE_QUEUE_TIMEOUT', 60),      // seconds per job
        'stall_interval'    => env('BEE_QUEUE_STALL_INTERVAL', 5000), // ms
        'remove_on_success' => env('BEE_QUEUE_REMOVE_ON_SUCCESS', false),
        'remove_on_failure' => env('BEE_QUEUE_REMOVE_ON_FAILURE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Settings
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'attempts'      => env('BEE_QUEUE_RETRY_ATTEMPTS', 3),
        'backoff'       => env('BEE_QUEUE_RETRY_BACKOFF', 'fixed'), // fixed | exponential
        'delay'         => env('BEE_QUEUE_RETRY_DELAY', 5),         // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Settings
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'path'       => env('BEE_QUEUE_DASHBOARD_PATH', 'bee-queue'),
        'middleware' => ['web'],
    ],
];
