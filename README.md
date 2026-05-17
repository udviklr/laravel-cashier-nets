# Laravel Cashier Nets

Laravel Cashier Nets provides a Cashier-inspired interface for Nets Easy / Nexi Checkout subscription billing in Laravel applications.

The package focuses on reusable subscription plumbing: creating hosted or embedded checkout sessions, storing local subscription state, processing payment webhooks, charging due subscriptions, and faking provider calls in tests.

> [!NOTE]
> The `1.x` release line supports normal Nets subscriptions. Unscheduled subscriptions, one-time checkout helpers, and bulk subscription charges are not part of the `1.x` API surface.

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Checkouts](docs/checkouts.md)
- [Webhooks](docs/webhooks.md)
- [Subscriptions and Renewals](docs/subscriptions-and-renewals.md)
- [Testing](docs/testing.md)
- [Upgrading](docs/upgrading.md)

## Version Support

Laravel Cashier Nets supports PHP `^8.1` and Laravel `10.x`, `11.x`, `12.x`, and `13.x`. The test matrix follows Laravel's own PHP requirements, so Laravel 10 is tested on PHP 8.1-8.3, Laravel 11 on PHP 8.2-8.4, Laravel 12 on PHP 8.2-8.5, and Laravel 13 on PHP 8.3-8.5.

## Installation

Install the package with Composer:

```shell
composer require udviklr/laravel-cashier-nets
```

Publish the configuration and migrations:

```shell
php artisan vendor:publish --tag="cashier-nets-config"
php artisan vendor:publish --tag="cashier-nets-migrations"
php artisan migrate
```

Add your Nets credentials and environment settings to `.env`:

```ini
NETS_SECRET_KEY=your-secret-api-key
NETS_CHECKOUT_KEY=your-checkout-key
NETS_SANDBOX=true
NETS_WEBHOOK_SECRET=your-random-webhook-secret
```

The secret key is used for server-to-server Payment API calls and must never be exposed to browsers. The checkout key is used by embedded checkout frontend code and may be exposed client-side.

## Billable Model

Add the `Billable` trait to the Eloquent model that owns subscriptions:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Udviklr\CashierNets\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Hosted Checkout

Hosted checkout redirects the customer to a Nexi-hosted page:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/subscribe', function (Request $request) {
    $checkout = $request->user()->newNetsSubscription('default')
        ->amount(9900)
        ->currency('DKK')
        ->intervalDays(30)
        ->description('Pro plan')
        ->reference('pro-plan')
        ->myReference('INV-2026-000123')
        ->returnUrl(route('billing.return'))
        ->termsUrl(route('terms'))
        ->endDate(now()->addYear())
        ->hostedCheckout();

    return $checkout->redirect();
});
```

The shorter `checkout()` method is an alias for `hostedCheckout()`.

## Embedded Checkout

Embedded checkout creates the payment object and returns a `paymentId` your frontend can pass to Nexi Checkout JS:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Udviklr\CashierNets\CashierNets;

Route::get('/billing/checkout-session', function (Request $request) {
    $checkout = $request->user()->newNetsSubscription('default')
        ->amount(9900)
        ->currency('DKK')
        ->intervalDays(30)
        ->description('Pro plan')
        ->merchantHandlesConsumerData()
        ->checkoutUrl(route('billing.checkout'))
        ->termsUrl(route('terms'))
        ->endDate(now()->addYear())
        ->embeddedCheckout();

    return response()->json([
        'paymentId' => $checkout->paymentId(),
        'checkoutKey' => CashierNets::checkoutKey(),
        'checkoutJsUrl' => CashierNets::checkoutJsUrl(),
    ]);
});
```

Your application is responsible for rendering the embedded checkout page with Nexi's Checkout JS SDK. This keeps the package frontend-agnostic for Blade, Livewire, Inertia, Vue, React, or other stacks.

Nets requires an `endDate` when creating a subscription. The package exposes this through `endDate()`, which accepts a `CarbonInterface`, `DateTimeInterface`, or date string.

Nets subscriptions use day-based intervals. For example, `intervalDays(30)` is a 30-day billing interval, not a calendar month. If your application stores its own local billing or access period, calculate it from the same interval days value you pass to Cashier Nets so it stays aligned with `nets_subscriptions.next_charge_at`.

Use `merchantHandlesConsumerData()` when your SaaS app collects billing identity itself, such as billing name, VAT, CVR, or invoice details. Nets may hide invoice or installment payment methods if full consumer data is not supplied to checkout.

## Subscription State

After checkout is created, a pending local subscription is stored. Webhooks should move it to active and persist provider identifiers.

```php
if ($user->subscribed()) {
    // The user has a valid subscription.
}

$subscription = $user->netsSubscription('default');

if ($subscription?->pastDue()) {
    // Ask the user to update payment details or retry later.
}
```

The package stores amounts in minor currency units. For example, `9900` is `99.00 DKK`.

See [Subscriptions and Renewals](docs/subscriptions-and-renewals.md) for state helpers, middleware examples, transactions, retry behavior, and scheduled renewal charging.

## Webhooks

By default, the package registers a webhook endpoint at:

```text
/nets/webhook
```

When creating Nets payments and subscription charges, the package includes configured webhook notifications. Nexi sends the configured `NETS_WEBHOOK_SECRET` value as the incoming `Authorization` header, and the package compares it exactly.

The v1 webhook handler processes:

- `payment.created`
- `payment.checkout.completed`
- `payment.charge.created.v2`
- `payment.charge.failed.v2`
- `payment.reservation.failed`

For local development, expose your Laravel app with a secure HTTPS tunnel such as Ngrok, Expose, or Laravel Herd share, because Nexi requires HTTPS webhook endpoints.

> [!IMPORTANT]
> Exclude the webhook route from Laravel CSRF protection, for example `nets/*` when using the default route prefix. See [Webhooks](docs/webhooks.md) for production setup, package events, idempotency, and troubleshooting.

Hosted checkout return routes are application-owned. Nets may return the payment identifier as lowercase `paymentid`, so accept both `paymentid` and `paymentId` before calling `syncNetsSubscriptionFromPayment()`. For session-authenticated callbacks from hosted checkout, prefer `SESSION_SAME_SITE=lax`.

## Renewals

The package owns local renewal scheduling through `nets_subscriptions.next_charge_at`. Nets subscriptions use day-based intervals, so align any local billing or access period with the same interval days value you sent through `intervalDays()`.

Charge due subscriptions with:

```shell
php artisan cashier-nets:charge-due
```

Schedule it in your Laravel app:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cashier-nets:charge-due')->everyTenMinutes();
```

You may also charge a subscription manually:

```php
$transaction = $user->netsSubscription('default')->charge([
    'reference' => 'pro-plan-renewal',
    'my_reference' => 'INV-2026-000124',
]);
```

Failed charge attempts are stored in `nets_transactions`. Retry behavior follows Nets' published retry guidance through the `cashier-nets.retry_policy` config values.

## Testing

Use `CashierNets::fake()` to fake Nets API responses and package webhook events:

```php
use Udviklr\CashierNets\CashierNets;

CashierNets::fake([
    'v1/payments' => [
        'paymentId' => 'pay_123',
        'hostedPaymentPageUrl' => 'https://test.checkout.dibspayment.eu/hostedpaymentpage/?checkoutKey=abc',
    ],
]);
```

Webhook events may be asserted with:

```php
CashierNets::assertWebhookReceived();
CashierNets::assertWebhookHandled();
```

## Production Checklist

Before enabling live billing:

- Set live `NETS_SECRET_KEY` and `NETS_CHECKOUT_KEY`.
- Set `NETS_SANDBOX=false`.
- Set a high-entropy `NETS_WEBHOOK_SECRET` value.
- Ensure your public `APP_URL` is HTTPS and resolves to the application.
- Exclude the package webhook route from Laravel CSRF protection.
- Confirm Nexi can reach `/nets/webhook`, or your configured webhook path.
- Schedule `cashier-nets:charge-due` if the app should charge renewals.
- Run a sandbox checkout and webhook test before switching credentials.

## Local Development

Run the package test suite:

```shell
composer test
composer analyse
```

Sandbox integration tests are available when you want to exercise the package against Nets directly. They are not part of the default test suite and require real Nets sandbox credentials:

```shell
NETS_INTEGRATION=true \
NETS_SECRET_KEY=your-sandbox-secret-key \
NETS_CHECKOUT_KEY=your-sandbox-checkout-key \
composer test:integration
```

The default integration suite retrieves created payments from Nets and verifies that order references and `myReference` merchant references were persisted.

Renewal charge coverage is available as an explicit opt-in because it creates real sandbox charge attempts against an existing active Nets subscription:

```shell
NETS_INTEGRATION=true \
NETS_SECRET_KEY=your-sandbox-secret-key \
NETS_TEST_SUBSCRIPTION_ID=active-sandbox-subscription-id \
composer test:integration:charges
```

The charge integration test verifies the renewal charge reference, `myReference`, and any returned `invoiceNumber` metadata.

See [Testing](docs/testing.md) for fake helpers, webhook assertions, and integration test overrides.

## License

Laravel Cashier Nets is open-sourced software licensed under the [MIT license](LICENSE.md).
