# Release Notes

## [1.3.0] - 2026-06-14

Added:

- Refund initiation: a low-level `NetsClient::refundCharge()` (validates `amount > 0`, sends the `Idempotency-Key` header) and a high-level `Transaction::refund(?int $amount = null, array $orderItems = [], ?string $idempotencyKey = null)`. A null amount issues a full refund of the charge; a smaller amount issues a partial refund, synthesizing a single zero-tax order item or validating caller-supplied VAT-aware `orderItems` against the existing Nets invariants. Refund confirmation is asynchronous and arrives via webhooks.
- `Transaction::remainingRefundable()`, so partial refunds are validated against the amount still refundable on the charge (a charge may be partially refunded multiple times).
- A `nets_refunds` table and `Refund` model (`STATUS_PENDING` / `STATUS_COMPLETED` / `STATUS_FAILED`) tracking each refund attempt, linked to its parent charge transaction.
- Semantic webhook events `RefundInitiated`, `RefundCompleted`, and `RefundFailed`, plus `payment.refund.initiated` / `payment.refund.completed` / `payment.refund.failed` wiring in the webhook controller and handler. A completed refund records the refund row, and once completed refunds cover the charge the charge transaction is flipped to `STATUS_REFUNDED`. The three event names are appended to the default `webhook_events` config. Because Nets sends `data.chargeId` only on `payment.refund.initiated` (the completed/failed events omit it), the handler resolves the charge from the locally recorded refund or the `paymentId`, so completions still flip the charge to refunded. The flip is serialized with a row lock so concurrent partial completions cannot both miss full coverage.
- `WebhookPayload::refundId()` accessor.
- `RefundException` (extends `NetsException`), thrown on a failed refund request and carrying the Nets status code and decoded error body via `NetsException::body()`.

Notes:

- Reusing a refund `idempotency_key` for a different amount is now rejected (it previously returned the existing refund or silently rewrote the reservation). Retry with the same amount, or use a new key.
- A late or duplicate refund webhook can no longer overwrite the amount, currency, or failure of a refund row that has already moved to a stronger state.
- `NetsException` is no longer `final` so `RefundException` can extend it, and its constructor signature is now `(string $message, int $code, ?array $body, ?Throwable $previous)`. Instances are still expected to be built via `NetsException::fromResponse()`; construct via the factory rather than `new`.

## [1.2.0] - 2026-06-10

Behavior changes:

- Webhook events are marked processed only after the semantic webhook events have been dispatched, and webhook processing now runs inside a database transaction. A consumer listener exception rolls back package writes, leaves the event unprocessed, and returns HTTP 500 so Nets redelivers — the full handler and listener path re-runs on redelivery, so listeners must be idempotent.
- Production deployments without `NETS_WEBHOOK_SECRET` now reject webhooks with HTTP 503 and a critical log entry until the secret is configured. Nets treats the 503 as a delivery failure and retries, so queued events flow through once the secret is set. Override the requirement with `NETS_WEBHOOK_AUTH_REQUIRED`.
- Automatically generated charge idempotency keys are now per attempt (`nets-sub-{id}-{dueAt}-a{attempt}`); each retry of a failed charge reaches Nets with a fresh key and records its own `nets_transactions` row instead of mutating the failed row back to pending. Explicit `idempotency_key` options are unchanged.

Added:

- Subscription lifecycle API: `cancel()` (optionally with a grace-period end date), `expire()`, `resume()`, and an `ended()` helper. `ends_at` is now honored by due-charge selection, `dueForCharge()`, and `charge()`.
- Atomic webhook event claim with a row lock, so concurrent deliveries of the same Nets event cannot both process it.
- Past-due retry collection: a `cashier-nets:retry-past-due` command, `Subscription::dueForRetry()` / `dueForRetryCollection()`, and a `retry_policy.backoff_days` schedule (retry n waits `backoff_days[n - 1]` after the most recent failure; the schedule length caps automatic retries).
- `ChargeAttemptFailed` event, fired when a local charge attempt errors before Nets reports an outcome, and error logs in both charge commands.
- `CashierNets::terminatePayment()` to terminate open (uncharged) checkout payments.
- Configurable webhook route middleware via `webhook_middleware` (the default stays `['web']`).
- The minimum supported Laravel 10 release is now 10.48.

## [1.1.1] - 2026-06-06

- Fix the package version reported in the Nets API `User-Agent` header, which still read `1.0.0` after the 1.1.0 release.
- Document VAT-aware custom order items in the README.

## [1.1.0] - 2026-06-06

- Add `SubscriptionBuilder::orderItems()` to set explicit, VAT-aware Nets order items for subscription checkouts.
- Accept an `order_items` option in `Subscription::charge()`, reusing a persisted `metadata.order_items` snapshot for recurring charges and falling back to the existing zero-tax item.
- Persist custom checkout order items into subscription metadata so the `cashier-nets:charge-due` command can reuse them for renewals.
- Validate custom order items against the Nets invariants (`netTotalAmount = unitPrice * quantity`, `grossTotalAmount = netTotalAmount + taxAmount`, `order.amount = sum of gross totals`) via `CashierNets::assertOrderItemsConsistent()`, failing fast before a charge is attempted.
- Note: a subscription's `metadata.order_items` is now used for recurring charges; previously this key was ignored.

## [1.0.0] - 2026-05-17

- Initial package scaffold.
- Add Nets API client, configuration, and test fakes.
- Add billable model trait, local subscription, transaction, customer, and webhook event models.
- Add hosted and embedded subscription checkout creation.
- Add webhook processing for checkout completion, successful charges, and failed reservation / charge events.
- Add parsed webhook payload accessors and semantic webhook events for checkout completion and charge outcomes.
- Add billable-scoped checkout finalization from a Nets payment ID.
- Keep checkout-completed webhook subscriptions pending when Nets does not include a subscription ID.
- Document lowercase hosted callback `paymentid` and local tunnel session/proxy requirements.
- Add single subscription charging and due-subscription Artisan command.
- Add Nets `myReference` support for subscription checkout and charge merchant references, including sandbox integration coverage.
- Add PHPUnit and PHPStan verification.
