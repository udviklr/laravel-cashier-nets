# Laravel Cashier Nets

Laravel Cashier Nets provides a Cashier-inspired interface for Nets Easy / Nexi Checkout subscription billing in Laravel applications.

The package focuses on reusable subscription plumbing: creating hosted or embedded checkout sessions, storing local subscription state, processing payment webhooks, charging due subscriptions, and faking provider calls in tests.

> [!WARNING]
> This package is under active development and targets a first v1 surface. It currently supports normal Nets subscriptions. Unscheduled subscriptions, one-time checkout helpers, and bulk subscription charges are deferred.

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

## Configuration

Add your Nets credentials and environment settings to `.env`:

```ini
NETS_SECRET_KEY=your-secret-api-key
NETS_CHECKOUT_KEY=your-checkout-key
NETS_SANDBOX=true
NETS_WEBHOOK_AUTHORIZATION=your-random-webhook-secret
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
        ->returnUrl(route('billing.return'))
        ->termsUrl(route('terms'))
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
        ->checkoutUrl(route('billing.checkout'))
        ->termsUrl(route('terms'))
        ->embeddedCheckout();

    return response()->json([
        'paymentId' => $checkout->paymentId(),
        'checkoutKey' => CashierNets::checkoutKey(),
        'checkoutJsUrl' => CashierNets::checkoutJsUrl(),
    ]);
});
```

Your application is responsible for rendering the embedded checkout page with Nexi's Checkout JS SDK. This keeps the package frontend-agnostic for Blade, Livewire, Inertia, Vue, React, or other stacks.

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

## Webhooks

By default, the package registers a webhook endpoint at:

```text
/nets/webhook
```

When creating Nets payments and subscription charges, the package includes configured webhook notifications. Nexi sends the configured `NETS_WEBHOOK_AUTHORIZATION` value as the incoming `Authorization` header, and the package compares it exactly.

The v1 webhook handler processes:

- `payment.created`
- `payment.checkout.completed`
- `payment.charge.created.v2`
- `payment.charge.failed.v2`
- `payment.reservation.failed`

For local development, expose your Laravel app with a secure HTTPS tunnel such as Ngrok, Expose, or Laravel Herd share, because Nexi requires HTTPS webhook endpoints.

## Renewals

The package owns local renewal scheduling through `nets_subscriptions.next_charge_at`. Charge due subscriptions with:

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
$transaction = $user->netsSubscription('default')->charge();
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

## Custom Models

You may extend the package models and assign your custom classes during application boot:

```php
use App\Models\Billing\Subscription;
use App\Models\Billing\Transaction;
use Udviklr\CashierNets\CashierNets;

public function boot(): void
{
    CashierNets::$subscriptionModel = Subscription::class;
    CashierNets::$transactionModel = Transaction::class;
}
```

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

Optional overrides are available for `NETS_TEST_AMOUNT`, `NETS_TEST_CURRENCY`, `NETS_TEST_RETURN_URL`, `NETS_TEST_CANCEL_URL`, `NETS_TEST_CHECKOUT_URL`, and `NETS_TEST_TERMS_URL`.

## License

Laravel Cashier Nets is open-sourced software licensed under the [MIT license](LICENSE.md).
