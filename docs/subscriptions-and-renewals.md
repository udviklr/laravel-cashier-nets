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
| `ended()` | `ends_at` has passed. |
| `dueForCharge()` | The active subscription has reached `next_charge_at` and has not ended. |
| `dueForRetry()` | The past-due subscription is ready for an automatic retry. |

By default, `past_due` subscriptions are not valid. You can allow past-due subscriptions to keep access:

```php
use Udviklr\CashierNets\CashierNets;

CashierNets::$deactivatePastDue = false;
```

## Canceling, Expiring, and Resuming

Stop the recurring charge engine with the lifecycle methods:

```php
$subscription = $user->netsSubscription('default');

// Cancel immediately; access ends now.
$subscription->cancel();

// Cancel with a grace period; valid() stays true until the end date.
$subscription->cancel($subscription->next_charge_at);

// Resume a canceled subscription.
$subscription->resume();

// Terminal: the subscription will never charge again.
$subscription->expire();
```

`cancel()` keeps `next_charge_at` intact so `resume()` is a pure status flip — no recomputation, no schedule drift. `resume()` only accepts canceled subscriptions and throws when the resulting `next_charge_at` would be `null`; pass an explicit date to re-arm the schedule:

```php
$subscription->resume(now()->addDays(30));
```

Canceled, expired, and ended (`ends_at` in the past) subscriptions are skipped by the charge commands, and `charge()` refuses them.

## Query Scopes

The package provides query scopes for common renewal queries:

```php
use Udviklr\CashierNets\Subscription;

$valid = Subscription::query()->valid()->get();

$due = Subscription::query()->dueForCharge()->get();
```

## Charging Due Subscriptions

The package owns local renewal scheduling through `nets_subscriptions.next_charge_at`. Nets subscriptions use day-based intervals. For example, `intervalDays(30)` is a 30-day billing interval, not a calendar month. If your application stores its own local billing or access period, calculate it from the same interval days value you pass to Cashier Nets so it stays aligned with `nets_subscriptions.next_charge_at`.

Charge due subscriptions with:

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
    'my_reference' => 'INV-2026-000124',
    'idempotency_key' => 'subscription-123-2026-05',
    'metadata' => [
        'plan' => 'pro',
    ],
]);
```

The subscription must have a Nets subscription ID and must not be canceled, expired, paused, or ended.

Use `reference` for the order reference and `my_reference` or `merchant_reference` for your merchant payment reference. Nexi limits `myReference` to 36 characters. The package sends the value to Nets as `myReference` in the subscription charge request. Any returned `invoiceNumber` is stored on transaction metadata as `invoice_number`.

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

## Idempotency Keys

Automatically generated charge idempotency keys are per attempt: the key base identifies the due period (`nets-sub-{id}-{dueAt}`) and an attempt suffix (`-a1`, `-a2`, …) increments with each failed attempt. A retry therefore reaches Nets as a new charge request with its own `nets_transactions` row, while a double dispatch of the same attempt reuses the previous key and row. Passing an explicit `idempotency_key` option bypasses this scheme entirely.

## Retrying Past-Due Subscriptions

Failed charge attempts mark the subscription `past_due` and store failure details. The package blocks retries for configured non-retryable response codes and limits retries within the configured rolling window.

Retry past-due subscriptions automatically with:

```shell
php artisan cashier-nets:retry-past-due
```

Schedule it alongside the charge command:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cashier-nets:retry-past-due')->hourly();
```

Selection follows `retry_policy.backoff_days`: retry `n` waits `backoff_days[n - 1]` after the most recent failure, and once the failure count passes the end of the array the subscription stays `past_due` until your application intervenes (for example by expiring access). Canceled and ended subscriptions are never retried.

A successful retry heals the subscription through the normal webhook flow: the `payment.charge.created.v2` event sets the status back to `active`, clears `failed_at`, and re-arms `next_charge_at`.

See [configuration](configuration.md#retry-policy) for retry policy settings.

## Charge Failure Observability

When a charge attempt errors locally — a timeout, a Nets 5xx, a rejected request — the package fires:

```php
Udviklr\CashierNets\Events\ChargeAttemptFailed
```

The event exposes the subscription, the failed transaction row, and the exception. It is distinct from the webhook-driven `ChargeFailed` event: `ChargeAttemptFailed` means the attempt itself errored before Nets reported an outcome, while `ChargeFailed` means Nets reported a failed charge. Use it to drive alerting and dunning email cadence:

```php
namespace App\Listeners;

use Udviklr\CashierNets\Events\ChargeAttemptFailed;

class NotifyBillingFailure
{
    public function handle(ChargeAttemptFailed $event): void
    {
        $event->subscription;
        $event->transaction->failure_message;
        $event->exception;
    }
}
```

Both charge commands also write an error log entry for every failed attempt, so scheduler output is not the only trace.
