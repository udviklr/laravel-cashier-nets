<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nets Easy Credentials
    |--------------------------------------------------------------------------
    |
    | The secret key is used for server-to-server Payment API requests. The
    | checkout key may be exposed to the frontend when using embedded checkout.
    |
    */

    'secret_key' => env('NETS_SECRET_KEY'),

    'checkout_key' => env('NETS_CHECKOUT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    */

    'sandbox' => filter_var(env('NETS_SANDBOX', true), FILTER_VALIDATE_BOOL),

    'api_urls' => [
        'sandbox' => env('NETS_SANDBOX_API_URL', 'https://test.api.dibspayment.eu'),
        'live' => env('NETS_API_URL', 'https://api.dibspayment.eu'),
    ],

    'checkout_js_urls' => [
        'sandbox' => env('NETS_SANDBOX_CHECKOUT_JS_URL', 'https://test.checkout.dibspayment.eu/v1/checkout.js?v=1'),
        'live' => env('NETS_CHECKOUT_JS_URL', 'https://checkout.dibspayment.eu/v1/checkout.js?v=1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes and Webhooks
    |--------------------------------------------------------------------------
    */

    'registers_routes' => true,

    'route_prefix' => 'nets',

    'webhook_path' => 'webhook',

    'webhook_authorization' => env('NETS_WEBHOOK_AUTHORIZATION'),

    'webhook_events' => [
        'payment.created',
        'payment.checkout.completed',
        'payment.charge.created.v2',
        'payment.charge.failed.v2',
        'payment.reservation.failed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Retry Policy
    |--------------------------------------------------------------------------
    |
    | Nets warns that some issuer response codes must not be retried. Other
    | failed charges should not be retried more than the configured number of
    | times in the configured rolling window.
    |
    */

    'retry_policy' => [
        'max_attempts' => 15,
        'window_days' => 30,
        'non_retryable_response_codes' => [
            '04',
            '14',
            '15',
            '41',
            '43',
            '46',
            '54',
            '57',
        ],
    ],
];
