<?php

namespace Udviklr\CashierNets\Exceptions;

use RuntimeException;
use Udviklr\CashierNets\Transaction;

class ChargeBlockedException extends RuntimeException
{
    public function __construct(public Transaction $transaction, public string $reason)
    {
        parent::__construct('Subscription charge blocked by '.$reason.' transaction ['.$transaction->getKey().'].');
    }
}
