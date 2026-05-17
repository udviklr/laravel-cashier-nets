<?php

namespace Udviklr\CashierNets\Events;

use Udviklr\CashierNets\Events\Concerns\InteractsWithWebhookPayload;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\WebhookEvent;
use Udviklr\CashierNets\Webhooks\WebhookPayload;

class ChargeFailed implements CashierNetsWebhookEvent
{
    use InteractsWithWebhookPayload;

    public function __construct(
        public WebhookPayload $payload,
        public WebhookEvent $webhookEvent,
        public ?Subscription $subscription = null,
        public ?Transaction $transaction = null,
    ) {
    }
}
