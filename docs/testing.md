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

`CashierNets::fake()` fakes package webhook events, including the generic webhook events, the semantic checkout / charge events, and the `ChargeAttemptFailed` observability event:

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

The default integration suite creates hosted and embedded sandbox subscription payments, retrieves them from Nets, verifies order and checkout details, verifies that `myReference` merchant references were persisted by Nets, verifies webhook notification payloads, verifies initial-charge checkout creation, and checks that failed Nets responses are surfaced as package exceptions.

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

The charge integration test also sends `my_reference`, retrieves the returned Nets payment, and verifies that Nets persisted `myReference`. If the retrieved payment contains an `invoiceNumber`, the test also checks that it matches the transaction metadata stored by the package.

Refund coverage is opt-in for the same reason — it issues a real refund against a **settled** sandbox charge. It needs the id of a charge that has settled and still has refundable balance:

```shell
NETS_INTEGRATION=true \
NETS_SECRET_KEY=your-sandbox-secret-key \
NETS_TEST_CHARGE_ID=settled-sandbox-charge-id \
composer test:integration:refunds
```

If you only have a payment id, pass `NETS_TEST_PAYMENT_ID` instead and the test resolves the charge from `GET /v1/payments/{paymentId}`:

```shell
NETS_INTEGRATION=true \
NETS_SECRET_KEY=your-sandbox-secret-key \
NETS_TEST_PAYMENT_ID=sandbox-payment-id \
composer test:integration:refunds
```

The test refunds a small amount with a unique idempotency key — so it can be re-run against the same charge until that charge's refundable balance is exhausted — then asserts that Nets returned a `refundId` and that a pending `Refund` row was persisted. Optional overrides:

- `NETS_TEST_REFUND_AMOUNT` (minor units, default `100`)
- `NETS_TEST_REFUND_PAYMENT_ID` (stored on the local charge transaction when resolving via `NETS_TEST_CHARGE_ID`)
- `NETS_TEST_AMOUNT`
- `NETS_TEST_CURRENCY`

To obtain a settled charge id, complete a real checkout: create a checkout that charges immediately, pay the hosted page with a [Nets sandbox test card](https://developer.nexigroup.com/nexi-checkout/en-EU/docs/test-environment/), then read `charges[].chargeId` from `GET /v1/payments/{paymentId}`. The charge must be **settled** before it can be refunded; sandbox settlement can lag, so a refund against an unsettled charge surfaces as a `RefundException`.

Refund *confirmation* is asynchronous and arrives via the `payment.refund.*` webhooks, which Nets delivers to a public URL rather than in-process. To exercise that end to end, serve the workbench app and expose it with a tunnel:

```shell
vendor/bin/testbench serve   # webhook endpoint: http://127.0.0.1:8000/webhook
```

Point a public tunnel (ngrok, cloudflared, …) at it and register the tunnel URL for the `payment.refund.initiated` / `payment.refund.completed` / `payment.refund.failed` events. The package's unit tests already cover the webhook handler with the documented payload shapes, so the tunnel is for end-to-end confidence rather than a required step. Note that only `payment.refund.initiated` carries `data.chargeId`; the completed and failed events omit it, so the handler resolves the charge from the locally recorded refund or the `paymentId`.

## Credentials are never committed

Sandbox and live Nets credentials must never be tracked in git. The tracked `phpunit.xml.dist` ships only placeholders (`test-secret-key` / `test-checkout-key`), and `phpunit.xml` is git-ignored. Provide real sandbox credentials at run time in one of two ways:

- export them in your shell — they override the `phpunit.xml.dist` placeholders because the `<env>` entries use `force="false"`; or
- copy `phpunit.xml.dist` to the git-ignored `phpunit.xml` and set them there for local runs.

Because the placeholder secret is `test-secret-key`, the integration suite **skips** on a fresh clone and in CI until real credentials are supplied — it never runs live by accident.
