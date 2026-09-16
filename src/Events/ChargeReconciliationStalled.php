<?php

namespace Udviklr\CashierNets\Events;

use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

class ChargeReconciliationStalled
{
    public function __construct(public Subscription $subscription, public Transaction $transaction) {}
}
