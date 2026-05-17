<?php

namespace Udviklr\CashierNets\Events;

use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\WebhookEvent;
use Udviklr\CashierNets\Webhooks\WebhookPayload;

interface CashierNetsWebhookEvent
{
    public function payload(): WebhookPayload;

    public function webhookEvent(): WebhookEvent;

    public function subscription(): ?Subscription;

    public function transaction(): ?Transaction;
}
