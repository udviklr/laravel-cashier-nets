# Upgrading

Laravel Cashier Nets follows semantic versioning. The `1.x` release line supports normal Nets subscriptions and keeps the documented API surface stable within that major version.

Before upgrading to a new release:

- Read `CHANGELOG.md`.
- Review any migration changes before publishing or running migrations.
- Check whether webhook event handling changed.
- Check whether renewal retry behavior changed.
- Run your billing feature tests with `CashierNets::fake()`.
- Run a sandbox checkout before deploying a billing upgrade to production.

## Upgrading to 1.4.0

Renewal attempts now remain pending when the provider outcome is uncertain. Before enabling renewal schedules after upgrading:

1. Check for duplicate non-null `idempotency_key` and `nets_charge_id` values in `nets_transactions`. Resolve those duplicates against the Nets portal before running the migration; the migration adds unique indexes and deliberately does not delete or merge financial records automatically.
2. Audit subscriptions whose timeouts or 5xx responses were recorded as `failed` before 1.4.0. Those rows remain eligible for retry under fresh keys. Check the portal for successful or duplicate charges before allowing the scheduler to retry them. Existing payments are not retrofitted with attempt references.
3. Run the package migration (`php artisan migrate`, publishing migrations first if that is your installation's workflow). It adds `uncertain_at`, `frozen_order`, and `nets_webhook_events.source`, alongside the unique indexes.
4. Keep the due and past-due schedules, and add `cashier-nets:reconcile-charges` every few minutes. Listen to `ChargeOutcomeUncertain` and `ChargeReconciliationStalled` as well as `ChargeAttemptFailed` for operational alerts.
5. Handle `UncertainChargeOutcomeException` and `ChargeBlockedException` in manual charge callers. A new key cannot bypass a pending attempt. Reusing a key returns its existing transaction without another POST, even when that row is terminal.

`resolve-charge --failed` refuses pending attempts that already carry a charge ID. Use read-only reconciliation or a verified payment outcome for those rows. Operator confirmation remains available for attempts without a charge ID after portal review.

Duplicate checks:

```sql
SELECT idempotency_key, COUNT(*) AS copies
FROM nets_transactions
WHERE idempotency_key IS NOT NULL
GROUP BY idempotency_key HAVING COUNT(*) > 1;

SELECT nets_charge_id, COUNT(*) AS copies
FROM nets_transactions
WHERE nets_charge_id IS NOT NULL
GROUP BY nets_charge_id HAVING COUNT(*) > 1;
```

For subscription charges, **`order.reference` now contains the exact attempt/idempotency key**. The caller's `reference` option still supplies the default line-item reference and `metadata.reference`; custom line-item references are preserved. Checkout references and explicit `my_reference`/`merchant_reference` wire behavior are unchanged. No merchant reference is generated automatically. Explicit keys must be non-empty UTF-8 strings of at most 63 bytes, with no surrounding whitespace, control/format characters, angle brackets, or backslashes. Invalid keys fail before reservation or HTTP.

Reconciliation and payment-based manual resolution can emit `ChargeSucceeded` or `ChargeFailed` through the existing event shape. Their `WebhookEvent` has `source = reconcile` or `manual` and a stable synthetic ID. Real webhooks have `source = webhook`. Listeners must remain idempotent: an exception rolls back finalization and allows a later run to retry. Once an attempt is terminal, later provider events enrich it without emitting another charge outcome.

`ChargeAttemptFailed` now means a definitive synchronous decline (4xx except 408/429), or an explicit operator confirmation. Structured decline codes take precedence over the HTTP status immediately. Transport errors and uncertain responses use `ChargeOutcomeUncertain` and leave the subscription's state and retry budget unchanged.

See [uncertain charge recovery](subscriptions-and-renewals.md#uncertain-charge-recovery) for operator commands and the distinction between identifying a payment and proving its outcome.

## Current 1.x Scope

Currently supported:

- Normal Nets subscriptions.
- Hosted subscription checkout.
- Embedded subscription checkout backend support.
- Local customer, subscription, transaction, and webhook event models.
- Webhook-driven local subscription state.
- Manual and scheduled subscription charges.
- Subscription lifecycle management (`cancel()`, `expire()`, `resume()`).
- Automatic past-due retry collection with configurable backoff.
- Terminating open checkout payments.
- Package fakes for tests.

Deferred:

- Unscheduled subscriptions.
- One-time checkout helpers.
- Bulk subscription charges.
- Frontend checkout components.
- Plan swapping APIs.
- Refunds, credits, and invoice rendering.

Do not assume full Laravel Cashier Stripe or Cashier Paddle API parity. This package is Cashier-inspired but Nets-native.
