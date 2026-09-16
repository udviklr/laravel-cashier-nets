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

Use `reference` for the readable default order-item reference and transaction metadata. Renewal `order.reference` is reserved for the exact attempt/idempotency key. Use `my_reference` or `merchant_reference` for your merchant payment reference. Nexi limits `myReference` to 36 characters. The package sends the value to Nets as `myReference` in the subscription charge request. Any returned `invoiceNumber` is stored on transaction metadata as `invoice_number`.

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

Automatically generated keys identify a due period (`nets-sub-{id}-{dueAt}`) and an attempt (`-a1`, `-a2`, …). Only definitive failed attempts increment the suffix. A retry uses a new key and transaction row. An explicit `idempotency_key` overrides this generation, but follows the same reservation and validation rules.

The package reserves the attempt while holding a subscription row lock, freezes the complete `order` in `frozen_order`, commits, and then makes its single POST. Repeating a key returns the original row without changing its snapshot or metadata and without sending another request. Keep calls to `charge()` outside your own database transactions so the reservation is committed before contacting Nets.

Any pending attempt blocks a new key, even for a later due period. `ChargeBlockedException` exposes `transaction` and `reason` (`in_flight` or `held`). The due and retry commands skip subscriptions with any pending attempt.

The exact key is sent as both the `Idempotency-Key` header and renewal `order.reference`. Keys must be non-empty UTF-8 strings of at most 63 bytes, without surrounding whitespace, control/format characters, `<`, `>`, or backslashes. They are never trimmed, truncated, or hashed. The caller's readable `reference` remains in the default item and `metadata.reference`; custom items retain their references. The frozen order excludes webhook notifications and authorization secrets.

## Uncertain Charge Recovery

A timeout, connection failure, 5xx, 408, 429, or 2xx response missing either provider ID leaves the attempt pending. The package sets `uncertain_at`, records the reason in metadata, emits `ChargeOutcomeUncertain`, and throws `UncertainChargeOutcomeException` with the transaction and original exception. The subscription's status, billing period, and failure budget stay unchanged.

`$transaction->held()` is true for a pending row without a charge ID when it is explicitly uncertain or older than `retry_policy.pending_grace_seconds` (120 seconds). A payment ID alone does not release the hold. A pending row with both IDs is awaiting a webhook; it still blocks another charge.

Schedule read-only recovery alongside renewal charging:

```php
Schedule::command('cashier-nets:reconcile-charges')->everyFiveMinutes();
```

Held attempts are looked up using their original key. A successful lookup supplies a payment ID, which is retrieved and verified against the subscription, exact order reference/key, frozen amount, and currency. Status 404 means either an unknown key or a declined attempt; it never releases a hold. The command also retrieves payments for identified attempts older than `reconcile.webhook_grace_minutes` (30 minutes). It finalizes only on terminal charge evidence and never sends a charge POST.

Webhooks use the same payment verification, including failure events that omit the subscription ID. A payment-created event can supply identity without finalizing an attempt. Temporary payment lookup failures leave the webhook unprocessed for redelivery. A payment with an empty summary is identified but unresolved; it is not proof of a decline.

Successful recovery uses the provider charge timestamp to advance billing. Reconciliation and manual resolution emit the normal charge outcome events once with a synthetic webhook event (`source = reconcile` or `manual`). Missing provider timestamps fall back to the resolution time with `metadata.provider_timestamp_missing = true`. A late webhook enriches the same terminal row without advancing billing or firing another outcome event.

An unresolved attempt older than `reconcile.alert_after_minutes` (60 minutes), measured from its creation, emits `ChargeReconciliationStalled` once. Use the operator commands to inspect it:

```shell
php artisan cashier-nets:uncertain-charges
php artisan cashier-nets:resolve-charge 123
php artisan cashier-nets:resolve-charge 123 --payment-id=PAYMENT_ID
```

The first command lists transaction/subscription IDs, amount, attempt key, readable reference, age, and reason. Resolution without options runs the status lookup. A supplied payment ID must match the exact attempt identity; an older renewal for the same amount is rejected if its order reference differs. There is no force bypass.

After confirming in the portal that the attempt was not charged, an operator can release it for the normal backoff retry under a fresh key:

```shell
php artisan cashier-nets:resolve-charge 123 --failed
```

Operator confirmation refuses a pending row that already has a charge ID. Resolve it through verified provider evidence instead; a reason or payment ID does not bypass this guard. The check runs after locking and reloading the attempt, so a concurrently received charge ID also prevents release.

When the portal shows an actual declined payment but retrieval exposes no terminal failure evidence, find it by the order reference/key and record that confirmation:

```shell
php artisan cashier-nets:resolve-charge 123 --payment-id=PAYMENT_ID --failed --reason="Portal confirms this attempt was declined"
```

Identity validation remains mandatory with `--payment-id`; adding `--failed` requires a reason. Operator confirmation records metadata, marks the pending attempt failed and subscription past due, and emits `ChargeAttemptFailed`. It preserves an existing structured provider decline code. If another local row already owns the discovered charge ID, the duplicate pending attempt is canceled with `metadata.superseded_by` instead.

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

Selection follows `retry_policy.backoff_days`: retry `n` waits `backoff_days[n - 1]` after the most recent failure, and once the failure count passes the end of the array the subscription stays `past_due` until your application intervenes (for example by expiring access). Canceled and ended subscriptions, and subscriptions with any pending attempt, are never selected for retry.

A successful retry heals the subscription through the normal webhook flow: the `payment.charge.created.v2` event sets the status back to `active`, clears `failed_at`, and re-arms `next_charge_at`.

See [configuration](configuration.md#retry-policy) for retry policy settings.

## Charge Failure Observability

When Nets definitively declines a synchronous request (4xx except 408/429), or an operator confirms that the attempt was not charged, the package fires:

```php
Udviklr\CashierNets\Events\ChargeAttemptFailed
```

The event exposes the subscription, the failed transaction row, and the exception. Structured provider decline codes and sources are stored immediately, so a code such as `14` prevents retries before any webhook arrives. A payment ID found in decline prose is only attached after a best-effort lookup validates its identity. A later failure webhook enriches the same row without another failure count or charge outcome event. Use the event for confirmed failures:

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

Listen to `ChargeOutcomeUncertain` (subscription, transaction, exception) and `ChargeReconciliationStalled` (subscription, transaction) for operational alerts. An uncertain outcome is not a confirmed payment failure and does not start dunning.
