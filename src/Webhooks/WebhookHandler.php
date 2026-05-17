<?php

namespace Udviklr\CashierNets\Webhooks;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

class WebhookHandler
{
    /**
     * Handle the given Nets webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        match ($this->eventName($payload)) {
            'payment.created' => $this->handlePaymentCreated($payload),
            'payment.checkout.completed' => $this->handleCheckoutCompleted($payload),
            'payment.charge.created', 'payment.charge.created.v2' => $this->handleChargeCreated($payload),
            'payment.charge.failed', 'payment.charge.failed.v2', 'payment.reservation.failed' => $this->handlePaymentFailed($payload),
            default => null,
        };
    }

    /**
     * Handle a payment.created event.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentCreated(array $payload): void
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return;
        }

        $updates = $this->subscriptionIdentifierUpdates($payload);

        if ($updates !== []) {
            $subscription->forceFill($updates)->save();
        }
    }

    /**
     * Handle a payment.checkout.completed event.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutCompleted(array $payload): void
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return;
        }

        $updates = array_merge($this->subscriptionIdentifierUpdates($payload), [
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $amount = $this->amount($payload);
        $currency = $this->currency($payload);

        if ($amount !== null) {
            $updates['amount'] = $amount;
        }

        if ($currency !== null) {
            $updates['currency'] = $currency;
        }

        $subscription->forceFill($updates)->save();
    }

    /**
     * Handle a successful charge event.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleChargeCreated(array $payload): void
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return;
        }

        $occurredAt = $this->occurredAt($payload);

        $this->recordTransaction($subscription, $payload, Transaction::STATUS_SUCCEEDED, $occurredAt);

        $updates = array_merge($this->subscriptionIdentifierUpdates($payload), [
            'status' => Subscription::STATUS_ACTIVE,
            'last_charged_at' => $occurredAt,
            'failed_at' => null,
        ]);

        if ($subscription->interval_days !== null) {
            $updates['next_charge_at'] = $occurredAt->copy()->addDays((int) $subscription->interval_days);
        }

        $subscription->forceFill($updates)->save();
    }

    /**
     * Handle a failed reservation or charge event.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentFailed(array $payload): void
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return;
        }

        $occurredAt = $this->occurredAt($payload);

        $this->recordTransaction($subscription, $payload, Transaction::STATUS_FAILED, $occurredAt);

        $subscription->forceFill(array_merge($this->subscriptionIdentifierUpdates($payload), [
            'status' => Subscription::STATUS_PAST_DUE,
            'failed_at' => $occurredAt,
        ]))->save();
    }

    /**
     * Record or update a transaction for a webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function recordTransaction(Subscription $subscription, array $payload, string $status, Carbon $occurredAt): void
    {
        $transactionModel = CashierNets::$transactionModel;
        $chargeId = $this->stringValue(Arr::get($payload, 'data.chargeId'));
        $paymentId = $this->paymentId($payload);

        if ($chargeId === null && $paymentId === null) {
            return;
        }

        $lookup = $chargeId !== null
            ? ['nets_charge_id' => $chargeId]
            : ['nets_payment_id' => $paymentId, 'status' => $status];

        $transaction = $transactionModel::query()->firstOrNew($lookup);

        $transaction->forceFill([
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
            'nets_payment_id' => $paymentId,
            'nets_charge_id' => $chargeId,
            'nets_subscription_id' => $this->subscriptionId($payload) ?? $subscription->nets_subscription_id,
            'nets_unscheduled_subscription_id' => $subscription->nets_unscheduled_subscription_id,
            'status' => $status,
            'amount' => $this->amount($payload),
            'currency' => $this->currency($payload),
            'failure_code' => $status === Transaction::STATUS_FAILED ? $this->stringValue(Arr::get($payload, 'data.error.code')) : null,
            'failure_message' => $status === Transaction::STATUS_FAILED ? $this->stringValue(Arr::get($payload, 'data.error.message')) : null,
            'billed_at' => $occurredAt,
            'metadata' => [
                'webhook_event_id' => $this->eventId($payload),
                'webhook_event_name' => $this->eventName($payload),
                'reconciliation_reference' => $this->stringValue(Arr::get($payload, 'data.reconciliationReference')),
                'my_reference' => $this->stringValue(Arr::get($payload, 'data.myReference')),
            ],
        ])->save();
    }

    /**
     * Find the local subscription that belongs to the webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function findSubscription(array $payload): ?Subscription
    {
        $subscriptionModel = CashierNets::$subscriptionModel;

        $subscriptionId = $this->subscriptionId($payload);

        if ($subscriptionId !== null) {
            $subscription = $subscriptionModel::query()
                ->where('nets_subscription_id', $subscriptionId)
                ->first();

            if ($subscription) {
                return $subscription;
            }
        }

        $paymentId = $this->paymentId($payload);

        if ($paymentId !== null) {
            return $subscriptionModel::query()
                ->where('nets_payment_id', $paymentId)
                ->first();
        }

        return null;
    }

    /**
     * Extract subscription identifier updates from the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function subscriptionIdentifierUpdates(array $payload): array
    {
        $subscriptionId = $this->subscriptionId($payload);

        return $subscriptionId === null ? [] : [
            'nets_subscription_id' => $subscriptionId,
        ];
    }

    /**
     * Get the webhook event identifier.
     *
     * @param  array<string, mixed>  $payload
     */
    public function eventId(array $payload): ?string
    {
        return $this->stringValue(Arr::get($payload, 'id'));
    }

    /**
     * Get the webhook event name.
     *
     * @param  array<string, mixed>  $payload
     */
    public function eventName(array $payload): string
    {
        return $this->stringValue(Arr::get($payload, 'event') ?? Arr::get($payload, 'eventName')) ?? 'unknown';
    }

    /**
     * Get the payment identifier from the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function paymentId(array $payload): ?string
    {
        return $this->stringValue(Arr::get($payload, 'data.paymentId'));
    }

    /**
     * Get the subscription identifier from the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function subscriptionId(array $payload): ?string
    {
        return $this->stringValue(Arr::get($payload, 'data.subscriptionId'));
    }

    /**
     * Get the event amount in minor units.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function amount(array $payload): ?int
    {
        $amount = Arr::get($payload, 'data.amount.amount')
            ?? Arr::get($payload, 'data.order.amount.amount');

        return is_numeric($amount) ? (int) $amount : null;
    }

    /**
     * Get the event currency.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function currency(array $payload): ?string
    {
        return $this->stringValue(Arr::get($payload, 'data.amount.currency')
            ?? Arr::get($payload, 'data.order.amount.currency'));
    }

    /**
     * Get the event occurrence time.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function occurredAt(array $payload): Carbon
    {
        $timestamp = $this->stringValue(Arr::get($payload, 'timestamp'));

        return $timestamp === null ? Carbon::now() : Carbon::parse($timestamp);
    }

    /**
     * Normalize a non-empty scalar value to a string.
     */
    protected function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
