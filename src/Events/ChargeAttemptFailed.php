<?php

namespace Udviklr\CashierNets\Events;

use Throwable;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

/**
 * Fired for a definitive synchronous decline or an operator-confirmed failure.
 * Ambiguous transport outcomes emit ChargeOutcomeUncertain instead. A later
 * webhook enriches this failed attempt without emitting ChargeFailed again.
 */
class ChargeAttemptFailed
{
    public function __construct(
        public Subscription $subscription,
        public Transaction $transaction,
        public Throwable $exception,
    ) {}
}
