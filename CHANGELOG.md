# Release Notes

## [Unreleased]

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
- Add PHPUnit and PHPStan verification.
