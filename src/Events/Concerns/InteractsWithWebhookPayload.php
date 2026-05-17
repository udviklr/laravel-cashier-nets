<?php

namespace Udviklr\CashierNets\Events\Concerns;

use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\WebhookEvent;
use Udviklr\CashierNets\Webhooks\WebhookPayload;

trait InteractsWithWebhookPayload
{
    public function payload(): WebhookPayload
    {
        return $this->payload;
    }

    public function webhookEvent(): WebhookEvent
    {
        return $this->webhookEvent;
    }

    public function subscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function transaction(): ?Transaction
    {
        return $this->transaction;
    }
}
