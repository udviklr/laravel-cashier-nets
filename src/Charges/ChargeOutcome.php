<?php

namespace Udviklr\CashierNets\Charges;

use Carbon\CarbonInterface;
use Udviklr\CashierNets\WebhookEvent;
use Udviklr\CashierNets\Webhooks\WebhookPayload;

final class ChargeOutcome
{
    /**
     * @param  array<string, mixed>  $providerData
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public ?string $paymentId,
        public ?string $chargeId,
        public ?CarbonInterface $providerOccurredAt,
        public string $source,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $providerData = [],
        public array $metadata = [],
        public ?WebhookEvent $webhookEvent = null,
        public ?WebhookPayload $webhookPayload = null,
    ) {}
}
