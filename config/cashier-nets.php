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

    /*
    | Middleware applied to the package webhook route. The default keeps the
    | historical 'web' group for backwards compatibility. A stateless stack
    | such as ['api', 'throttle:60,1'] is recommended for the machine-to-
    | machine endpoint; if you keep the web group, exempt the route from
    | CSRF verification in your application.
    */

    'webhook_middleware' => ['web'],

    'webhook_authorization' => env('NETS_WEBHOOK_SECRET'),

    /*
    | The shared Authorization header is the only authentication Nets webhooks
    | carry. When this flag is true, webhooks are rejected with HTTP 503 until
    | a webhook authorization secret is configured. When null, the secret is
    | required in the production environment and optional everywhere else.
    */

    'webhook_authorization_required' => env('NETS_WEBHOOK_AUTH_REQUIRED'),

    'webhook_events' => [
        'payment.created',
        'payment.checkout.completed',
        'payment.charge.created.v2',
        'payment.charge.failed.v2',
        'payment.reservation.failed',
        'payment.refund.initiated',
        'payment.refund.completed',
        'payment.refund.failed',
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
        // Automatic past-due retry backoff: retry n waits backoff_days[n - 1]
        // after the most recent failure. Once the failure count passes the end
        // of the array, cashier-nets:retry-past-due stops selecting the
        // subscription and it stays past due until a consumer intervenes.
        'backoff_days' => [1, 3, 5],
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
