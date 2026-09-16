<?php

namespace Udviklr\CashierNets\Exceptions;

use RuntimeException;
use Throwable;
use Udviklr\CashierNets\Transaction;

class UncertainChargeOutcomeException extends RuntimeException
{
    public function __construct(public Transaction $transaction, Throwable $previous)
    {
        parent::__construct('The outcome of charge transaction ['.$transaction->getKey().'] is uncertain.', 0, $previous);
    }
}
