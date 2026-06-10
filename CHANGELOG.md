# Release Notes

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
