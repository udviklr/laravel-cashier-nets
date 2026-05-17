# Testing

Use `CashierNets::fake()` to fake Nets API responses and package webhook events in application tests.

## Fake API Responses

```php
use Udviklr\CashierNets\CashierNets;

CashierNets::fake([
    'v1/payments' => [
        'paymentId' => 'pay_123',
        'hostedPaymentPageUrl' => 'https://test.checkout.dibspayment.eu/hostedpaymentpage/?checkoutKey=abc',
    ],
]);
```

The fake endpoint key is relative to the configured Nets API base URL.

You may also build the fake fluently:

```php
CashierNets::fake()
    ->response('v1/payments', ['paymentId' => 'pay_123'])
    ->response('v1/payments/pay_123', [
        'payment' => [
            'paymentId' => 'pay_123',
            'subscription' => [
                'id' => 'sub_123',
            ],
        ],
    ]);
```

## Fake Errors

```php
CashierNets::fake()
    ->error('v1/payments', 'Validation failed.', 422);
```

Calls to that endpoint will throw `Udviklr\CashierNets\Exceptions\NetsException`.

## Webhook Event Assertions

`CashierNets::fake()` fakes package webhook events, including the generic webhook events and the semantic checkout / charge events:

```php
CashierNets::assertWebhookReceived();
CashierNets::assertWebhookHandled();
```

Assertions may receive a callback:

```php
use Udviklr\CashierNets\Events\WebhookReceived;

CashierNets::assertWebhookReceived(function (WebhookReceived $event) {
    return ($event->payload['eventName'] ?? null) === 'payment.checkout.completed';
});
```

## Testing Application Webhook Handling

You can post webhook payloads to the package route in feature tests:

```php
$this->postJson('/nets/webhook', [
    'eventName' => 'payment.checkout.completed',
    'id' => 'evt_checkout_completed',
    'data' => [
        'paymentId' => 'pay_123',
    ],
])->assertOk();
```

If `cashier-nets.webhook_authorization` is configured, include the matching `Authorization` header.

## Package Test Suite

Run the package tests:

```shell
composer test
composer analyse
```

Sandbox integration tests are opt-in and require real Nets sandbox credentials:

```shell
NETS_INTEGRATION=true \
NETS_SECRET_KEY=your-sandbox-secret-key \
NETS_CHECKOUT_KEY=your-sandbox-checkout-key \
composer test:integration
```

The default integration suite creates hosted and embedded sandbox subscription payments, retrieves them from Nets, verifies order and checkout details, verifies webhook notification payloads, verifies initial-charge checkout creation, and checks that failed Nets responses are surfaced as package exceptions.

Optional overrides:

- `NETS_TEST_AMOUNT`
- `NETS_TEST_CURRENCY`
- `NETS_TEST_END_DATE`
- `NETS_TEST_RETURN_URL`
- `NETS_TEST_CANCEL_URL`
- `NETS_TEST_CHECKOUT_URL`
- `NETS_TEST_TERMS_URL`
- `NETS_TEST_WEBHOOK_AUTHORIZATION`
- `NETS_TEST_WEBHOOK_EVENTS`

Renewal charge coverage is explicit opt-in because it creates real sandbox charge attempts against an existing active Nets subscription:

```shell
NETS_INTEGRATION=true \
NETS_SECRET_KEY=your-sandbox-secret-key \
NETS_TEST_SUBSCRIPTION_ID=active-sandbox-subscription-id \
composer test:integration:charges
```
