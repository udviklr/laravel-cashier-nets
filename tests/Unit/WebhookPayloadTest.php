<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Udviklr\CashierNets\Webhooks\WebhookPayload;

class WebhookPayloadTest extends TestCase
{
    public function test_it_extracts_common_webhook_fields_from_v2_payloads(): void
    {
        $payload = WebhookPayload::from([
            'id' => 'evt_123',
            'event' => 'payment.charge.created.v2',
            'createdAt' => '2026-04-30T05:04:00.4502+00:00',
            'data' => [
                'paymentId' => 'pay_123',
                'chargeId' => 'charge_123',
                'subscription' => [
                    'id' => 'sub_123',
                ],
                'order' => [
                    'amount' => [
                        'amount' => '9900',
                        'currency' => 'DKK',
                    ],
                ],
            ],
        ]);

        $this->assertSame('evt_123', $payload->eventId());
        $this->assertSame('payment.charge.created.v2', $payload->eventName());
        $this->assertSame('pay_123', $payload->paymentId());
        $this->assertSame('charge_123', $payload->chargeId());
        $this->assertSame('sub_123', $payload->subscriptionId());
        $this->assertSame(9900, $payload->amount());
        $this->assertSame('DKK', $payload->currency());
        $this->assertSame('2026-04-30 05:04:00', $payload->occurredAt()?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_supports_legacy_names_and_partial_payloads(): void
    {
        $payload = WebhookPayload::from([
            'eventName' => 'payment.charge.failed',
            'created' => '2026-04-30T05:04:00+00:00',
            'data' => [
                'paymentid' => 'pay_lowercase',
                'amount' => '1234',
                'currency' => 'EUR',
            ],
        ]);

        $this->assertNull($payload->eventId());
        $this->assertSame('payment.charge.failed', $payload->eventName());
        $this->assertSame('pay_lowercase', $payload->paymentId());
        $this->assertNull($payload->chargeId());
        $this->assertNull($payload->subscriptionId());
        $this->assertSame(1234, $payload->amount());
        $this->assertSame('EUR', $payload->currency());
        $this->assertSame('2026-04-30 05:04:00', $payload->occurredAt()?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_returns_null_for_missing_or_invalid_optional_values(): void
    {
        $payload = WebhookPayload::from([
            'timestamp' => 'not-a-date',
            'data' => [
                'paymentId' => '',
                'amount' => ['amount' => 'not-a-number'],
            ],
        ]);

        $this->assertSame('unknown', $payload->eventName());
        $this->assertNull($payload->paymentId());
        $this->assertNull($payload->amount());
        $this->assertNull($payload->occurredAt());
    }
}
