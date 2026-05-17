<?php

namespace Udviklr\CashierNets\Events;

class WebhookHandled
{
    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {
    }
}
