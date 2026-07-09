<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Finvalda API Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your Finvalda web service. Use V2 (FvsServicePure) for
    | clean REST JSON/XML responses.
    |
    | Example: https://your-server.com/FvsServicePure.svc
    |
    */
    'base_url' => env('FINVALDA_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Finvalda employee credentials used for API authentication.
    |
    */
    'username' => env('FINVALDA_USERNAME', ''),
    'password' => env('FINVALDA_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Connection String
    |--------------------------------------------------------------------------
    |
    | Database connection string. Required for some server configurations.
    |
    */
    'conn_string' => env('FINVALDA_CONN_STRING'),

    /*
    |--------------------------------------------------------------------------
    | Company ID
    |--------------------------------------------------------------------------
    |
    | Used when the service handles multiple databases. Identifies which
    | company database to connect to.
    |
    */
    'company_id' => env('FINVALDA_COMPANY_ID'),

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    |
    | Response language: 0 = Lithuanian, 1 = English
    |
    */
    'language' => (int) env('FINVALDA_LANGUAGE', 0),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | HTTP request timeout in seconds.
    |
    */
    'timeout' => (int) env('FINVALDA_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Response Filtering
    |--------------------------------------------------------------------------
    |
    | Options to reduce response payload size.
    |
    */
    'remove_empty_string_tags' => (bool) env('FINVALDA_REMOVE_EMPTY_STRINGS', false),
    'remove_zero_number_tags' => (bool) env('FINVALDA_REMOVE_ZERO_NUMBERS', false),
    'remove_new_lines' => (bool) env('FINVALDA_REMOVE_NEW_LINES', false),

    /*
    |--------------------------------------------------------------------------
    | Outbound Float Normalization
    |--------------------------------------------------------------------------
    |
    | Round every outbound float before JSON encoding to strip PHP binary-float
    | artifacts (e.g. 0.30000000000000004) from Finvalda request payloads. The
    | default precision is high enough to preserve any genuine accounting value
    | while discarding representation noise.
    |
    */
    'normalize_floats' => (bool) env('FINVALDA_NORMALIZE_FLOATS', true),
    'float_precision' => (int) env('FINVALDA_FLOAT_PRECISION', 10),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Laravel log channel for SDK request/response debug records. Leave null
    | to disable SDK logging.
    |
    */
    'log_channel' => env('FINVALDA_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | Automatic retries for transient failures (network errors, 5xx, 429)
    | with exponential backoff. Disabled by default.
    |
    */
    'retry' => [
        'enabled' => (bool) env('FINVALDA_RETRY_ENABLED', false),
        'max_attempts' => (int) env('FINVALDA_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms' => (int) env('FINVALDA_RETRY_DELAY_MS', 100),
        'multiplier' => (float) env('FINVALDA_RETRY_MULTIPLIER', 2.0),
        'max_delay_ms' => (int) env('FINVALDA_RETRY_MAX_DELAY_MS', 10000),
    ],

];
