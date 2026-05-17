<?php

namespace Udviklr\CashierNets\Webhooks;

use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

final class WebhookHandlingResult
{
    public function __construct(
        public WebhookPayload $payload,
        public ?Subscription $subscription = null,
        public ?Transaction $transaction = null,
    ) {
    }
}
