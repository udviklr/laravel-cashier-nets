# Refunds

Refunds are issued against a settled **charge**. Each succeeded `Transaction` stores the Nets `nets_charge_id` it was charged against, and `Transaction::refund()` wraps the Nets *Refund a charge* endpoint.

Refund confirmation is asynchronous: initiating a refund records a pending `Refund` row, and the `payment.refund.completed` / `payment.refund.failed` webhooks settle it. Make sure your [webhooks](webhooks.md) are configured.

## Issuing a refund

Call `refund()` on the succeeded transaction:

```php
use Udviklr\CashierNets\Transaction;

$transaction = Transaction::query()
    ->where('nets_charge_id', $chargeId)
    ->firstOrFail();

// Full refund of the charge amount.
$refund = $transaction->refund();

// Partial refund of 25.00 DKK (in minor units).
$refund = $transaction->refund(2500);
```

A `null` amount issues a full refund of the charge amount. A smaller amount issues a partial refund. A charge may be partially refunded multiple times; `Transaction::remainingRefundable()` returns the amount still refundable, and `refund()` rejects an amount greater than that.

The returned `Udviklr\CashierNets\Refund` is created with status `pending`. Its `nets_refund_id` is set from the Nets response; the status moves to `completed` or `failed` when the matching webhook arrives.

### Partial refunds and VAT

Nets requires a complete order-item line spec for a partial refund. When you pass only an amount, the package sends a single zero-tax order item totalling that amount. To refund VAT-aware line items precisely, pass your own `orderItems`, which are validated against the same Nets invariants as charges (`netTotalAmount = unitPrice * quantity`, `grossTotalAmount = netTotalAmount + taxAmount`, and the gross totals equal the refund amount):

```php
$transaction->refund(5000, [[
    'reference' => 'business-yearly',
    'name' => 'Business - Yearly',
    'quantity' => 1,
    'unit' => 'pcs',
    'unitPrice' => 4000,
    'taxRate' => 2500,
    'taxAmount' => 1000,
    'grossTotalAmount' => 5000,
    'netTotalAmount' => 4000,
]]);
```

### Idempotency

Money movement is protected by an `Idempotency-Key` header. The package generates a per-attempt key (`nets-refund-{chargeId}-a{attempt}`) and records it on the `Refund` row. Pass your own key as the third argument when your application owns the retry identity:

```php
$transaction->refund(2500, [], 'your-refund-idempotency-key');
```

When Nets returns an error response, `refund()` throws `Udviklr\CashierNets\Exceptions\RefundException` (a `NetsException`), carrying the Nets status code and the decoded error body via `body()`. The refund was rejected and never processed, so its row is marked `failed` and the amount is released for a fresh attempt.

If the request fails *without* a response — for example a connection timeout — the outcome is unknown: Nets may have processed the refund. The row is left `pending` so it keeps reserving the amount, and a fresh full-amount call is rejected as exceeding the remaining refundable balance. Reconcile by retrying with the **same** idempotency key, which Nets de-duplicates rather than refunding twice:

```php
$transaction->refund(2500, [], $key); // first call times out
$transaction->refund(2500, [], $key); // safe to retry with the same key
```

## Tracking refunds

Refunds are stored in `nets_refunds` and modeled by `Udviklr\CashierNets\Refund`:

```php
$transaction->refunds;           // all refunds against this charge
$refund->pending();              // bool
$refund->completed();            // bool
$refund->failed();               // bool
$refund->amount();               // formatted, e.g. "kr. 25.00"
$refund->transaction;            // the parent charge transaction
```

When completed refunds cover the full charge amount, the package flips the charge `Transaction` to `STATUS_REFUNDED`. Partial refunds leave the transaction `succeeded` until the charge is fully refunded.

## Reacting to refunds

Listen for the semantic webhook events to sync application state. Keep listeners idempotent — a redelivered `completed` event must not credit a customer twice:

```php
namespace App\Listeners;

use Udviklr\CashierNets\Events\RefundCompleted;

class SyncCompletedRefund
{
    public function handle(RefundCompleted $event): void
    {
        $refund = $event->refund();          // resolved package Refund (or null)
        $transaction = $event->transaction;  // the charge transaction
        $refundId = $event->payload->refundId();

        // Note: only payment.refund.initiated carries data.chargeId; the
        // completed/failed events do not. Read the charge from the resolved
        // models instead of the payload:
        $chargeId = $transaction?->nets_charge_id ?? $refund?->nets_charge_id;

        // Mark your application's payment refunded, credit the invoice, etc.
    }
}
```

`RefundInitiated`, `RefundCompleted`, and `RefundFailed` are dispatched only when the package processes the webhook (not on duplicate deliveries), after the `Refund` row has been persisted.
