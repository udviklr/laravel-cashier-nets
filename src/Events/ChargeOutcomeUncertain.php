<?php

namespace Udviklr\CashierNets\Events;

use Throwable;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

class ChargeOutcomeUncertain
{
    public function __construct(public Subscription $subscription, public Transaction $transaction, public Throwable $exception) {}
}
