# Subscriptions and Renewals

Laravel Cashier Nets stores subscription state locally so your application can answer access questions without polling Nets for every request.

## Subscription State

Get the current subscription:

```php
$subscription = $user->netsSubscription('default');
```

Check access:

```php
if ($user->subscribed()) {
    // Active, trialing, on grace period, or allowed past due.
}
```

Subscription helpers include:

| Method | Meaning |
| --- | --- |
| `valid()` | The subscription should grant access. |
| `pending()` | Checkout was created but not completed. |
| `active()` | The subscription is active. |
| `onTrial()` | The subscription is trialing and the trial has not expired. |
| `pastDue()` | A payment or charge failed. |
| `paused()` | The subscription is paused locally. |
| `canceled()` | The subscription is canceled locally. |
| `expired()` | The subscription is expired locally. |
| `onGracePeriod()` | `ends_at` is still in the future. |
| `dueForCharge()` | The active subscription has reached `next_charge_at`. |

By default, `past_due` subscriptions are not valid. You can allow past-due subscriptions to keep access:

```php
use Udviklr\CashierNets\CashierNets;

CashierNets::$deactivatePastDue = false;
```

## Query Scopes

The package provides query scopes for common renewal queries:

```php
use Udviklr\CashierNets\Subscription;

$valid = Subscription::query()->valid()->get();

$due = Subscription::query()->dueForCharge()->get();
```

## Charging Due Subscriptions

The package owns local renewal scheduling through `nets_subscriptions.next_charge_at`. Charge due subscriptions with:

```shell
php artisan cashier-nets:charge-due
```

Limit the number of subscriptions processed in one run:

```shell
php artisan cashier-nets:charge-due --limit=50
```

Schedule the command in your Laravel app:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cashier-nets:charge-due')->everyTenMinutes();
```

The command attempts each due subscription independently. It returns a failure exit code if any charge attempt fails.

## Manual Charges

You may charge a subscription manually:

```php
$transaction = $user->netsSubscription('default')->charge();
```

You may override charge details:

```php
$transaction = $user->netsSubscription('default')->charge([
    'amount' => 9900,
    'currency' => 'DKK',
    'description' => 'Pro plan renewal',
    'reference' => 'pro-plan-renewal',
    'idempotency_key' => 'subscription-123-2026-05',
    'metadata' => [
        'plan' => 'pro',
    ],
]);
```

The subscription must have a Nets subscription ID and must not be canceled, expired, or paused.

## Transactions

Charge attempts and webhook outcomes are stored in `nets_transactions`.

```php
$transactions = $user->netsTransactions()->latest()->get();

foreach ($transactions as $transaction) {
    if ($transaction->succeeded()) {
        // Payment succeeded.
    }

    if ($transaction->failed() && $transaction->retryable()) {
        // The failure can be retried.
    }
}
```

Amounts can be formatted for display:

```php
$subscription->amount();
$transaction->amount();
```

## Retry Behavior

Failed charge attempts mark the subscription `past_due` and store failure details. The package blocks retries for configured non-retryable response codes and limits retries within the configured rolling window.

See [configuration](configuration.md#retry-policy) for retry policy settings.

