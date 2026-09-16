<?php

namespace Udviklr\CashierNets\Webhooks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Udviklr\CashierNets\Charges\ChargeOutcome;
use Udviklr\CashierNets\Transaction;

final class WebhookPayload
{
    /**
     * Create a new webhook payload wrapper.
     *
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private array $payload,
    ) {}

    /**
     * Create a payload wrapper from a raw Nets webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        return new self($payload);
    }

    public static function fromChargeOutcome(Transaction $row, ChargeOutcome $outcome, string $eventId, CarbonInterface $occurredAt): self
    {
        return self::from([
            'id' => $eventId,
            'event' => $outcome->status === Transaction::STATUS_SUCCEEDED ? 'payment.charge.created.v2' : 'payment.charge.failed.v2',
            'timestamp' => $occurredAt->toIso8601String(),
            'data' => [
                'paymentId' => $outcome->paymentId, 'chargeId' => $outcome->chargeId,
                'subscriptionId' => $row->nets_subscription_id,
                'amount' => ['amount' => $row->amount, 'currency' => $row->currency],
                'error' => ['code' => $outcome->failureCode, 'message' => $outcome->failureMessage],
            ],
            'payment' => $outcome->providerData,
        ]);
    }

    /**
     * Get the original webhook payload.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->payload;
    }

    /**
     * Get the webhook event identifier.
     */
    public function eventId(): ?string
    {
        return $this->stringValue(Arr::get($this->payload, 'id'));
    }

    /**
     * Get the webhook event name.
     */
    public function eventName(): string
    {
        return $this->stringValue(Arr::get($this->payload, 'event') ?? Arr::get($this->payload, 'eventName')) ?? 'unknown';
    }

    /**
     * Get the payment identifier from the payload.
     */
    public function paymentId(): ?string
    {
        return $this->stringValue(
            Arr::get($this->payload, 'data.paymentId')
            ?? Arr::get($this->payload, 'data.paymentid')
        );
    }

    /**
     * Get the charge identifier from the payload.
     */
    public function chargeId(): ?string
    {
        return $this->stringValue(Arr::get($this->payload, 'data.chargeId'));
    }

    /**
     * Get the refund identifier from the payload.
     */
    public function refundId(): ?string
    {
        return $this->stringValue(Arr::get($this->payload, 'data.refundId'));
    }

    /**
     * Get the subscription identifier from the payload.
     */
    public function subscriptionId(): ?string
    {
        return $this->stringValue(
            Arr::get($this->payload, 'data.subscriptionId')
            ?? Arr::get($this->payload, 'data.subscription.id')
        );
    }

    /**
     * Get the event amount in minor units.
     */
    public function amount(): ?int
    {
        $amount = Arr::get($this->payload, 'data.order.amount.amount')
            ?? Arr::get($this->payload, 'data.amount.amount')
            ?? Arr::get($this->payload, 'data.amount');

        return is_numeric($amount) ? (int) $amount : null;
    }

    /**
     * Get the event currency.
     */
    public function currency(): ?string
    {
        return $this->stringValue(
            Arr::get($this->payload, 'data.order.amount.currency')
            ?? Arr::get($this->payload, 'data.amount.currency')
            ?? Arr::get($this->payload, 'data.currency')
        );
    }

    /**
     * Get the event occurrence time.
     */
    public function occurredAt(): ?CarbonInterface
    {
        $timestamp = $this->stringValue(
            Arr::get($this->payload, 'created')
            ?? Arr::get($this->payload, 'createdAt')
            ?? Arr::get($this->payload, 'timestamp')
        );

        if ($timestamp === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalize a non-empty scalar value to a string.
     */
    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
