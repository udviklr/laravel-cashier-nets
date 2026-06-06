# Release Notes

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
