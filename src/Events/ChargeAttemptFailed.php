<?php

namespace Udviklr\CashierNets\Events;

use Throwable;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

/**
 * Fired when a local charge attempt errors before Nets reports an outcome.
 *
 * Distinct from the webhook-driven ChargeFailed event: this one means the
 * attempt itself errored locally (timeout, Nets 5xx, rejected request),
 * while ChargeFailed means Nets reported a failed charge.
 */
class ChargeAttemptFailed
{
    public function __construct(
        public Subscription $subscription,
        public Transaction $transaction,
        public Throwable $exception,
    ) {
    }
}
