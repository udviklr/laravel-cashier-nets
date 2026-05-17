# Upgrading

Laravel Cashier Nets is under active development and targets a first v1 surface.

Before upgrading to a new release:

- Read `CHANGELOG.md`.
- Review any migration changes before publishing or running migrations.
- Check whether webhook event handling changed.
- Check whether renewal retry behavior changed.
- Run your billing feature tests with `CashierNets::fake()`.
- Run a sandbox checkout before deploying a billing upgrade to production.

## Current V1 Scope

Currently supported:

- Normal Nets subscriptions.
- Hosted subscription checkout.
- Embedded subscription checkout backend support.
- Local customer, subscription, transaction, and webhook event models.
- Webhook-driven local subscription state.
- Manual and scheduled subscription charges.
- Package fakes for tests.

Deferred:

- Unscheduled subscriptions.
- One-time checkout helpers.
- Bulk subscription charges.
- Frontend checkout components.
- Plan swapping and cancellation APIs.
- Refunds, credits, and invoice rendering.

Do not assume full Laravel Cashier Stripe or Cashier Paddle API parity. This package is Cashier-inspired but Nets-native.

