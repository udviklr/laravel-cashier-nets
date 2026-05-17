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
    public function handle(array $payload): WebhookHandlingResult
    {
        $payload = WebhookPayload::from($payload);

        return match ($payload->eventName()) {
            'payment.created' => $this->handlePaymentCreated($payload),
            'payment.checkout.completed' => $this->handleCheckoutCompleted($payload),
            'payment.charge.created', 'payment.charge.created.v2' => $this->handleChargeCreated($payload),
            'payment.charge.failed', 'payment.charge.failed.v2', 'payment.reservation.failed' => $this->handlePaymentFailed($payload),
            default => new WebhookHandlingResult($payload),
        };
    }

    /**
     * Handle a payment.created event.
     */
    protected function handlePaymentCreated(WebhookPayload $payload): WebhookHandlingResult
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return new WebhookHandlingResult($payload);
        }

        $updates = $this->subscriptionIdentifierUpdates($payload);

        if ($updates !== []) {
            $subscription->forceFill($updates)->save();
        }

        return new WebhookHandlingResult($payload, $subscription->refresh());
    }

    /**
     * Handle a payment.checkout.completed event.
     */
    protected function handleCheckoutCompleted(WebhookPayload $payload): WebhookHandlingResult
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return new WebhookHandlingResult($payload);
        }

        $updates = $this->subscriptionIdentifierUpdates($payload);

        if ($payload->subscriptionId() !== null || $subscription->nets_subscription_id !== null) {
            $updates['status'] = Subscription::STATUS_ACTIVE;
        }

        $amount = $payload->amount();
        $currency = $payload->currency();

        if ($amount !== null) {
            $updates['amount'] = $amount;
        }

        if ($currency !== null) {
            $updates['currency'] = $currency;
        }

        $subscription->forceFill($updates)->save();

        return new WebhookHandlingResult($payload, $subscription->refresh());
    }

    /**
     * Handle a successful charge event.
     */
    protected function handleChargeCreated(WebhookPayload $payload): WebhookHandlingResult
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return new WebhookHandlingResult($payload);
        }

        $occurredAt = $this->occurredAt($payload);

        $transaction = $this->recordTransaction($subscription, $payload, Transaction::STATUS_SUCCEEDED, $occurredAt);

        $updates = array_merge($this->subscriptionIdentifierUpdates($payload), [
            'status' => Subscription::STATUS_ACTIVE,
            'last_charged_at' => $occurredAt,
            'failed_at' => null,
        ]);

        if ($subscription->interval_days !== null) {
            $updates['next_charge_at'] = $occurredAt->copy()->addDays((int) $subscription->interval_days);
        }

        $subscription->forceFill($updates)->save();

        return new WebhookHandlingResult($payload, $subscription->refresh(), $transaction);
    }

    /**
     * Handle a failed reservation or charge event.
     */
    protected function handlePaymentFailed(WebhookPayload $payload): WebhookHandlingResult
    {
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return new WebhookHandlingResult($payload);
        }

        $occurredAt = $this->occurredAt($payload);

        $transaction = $this->recordTransaction($subscription, $payload, Transaction::STATUS_FAILED, $occurredAt);

        $subscription->forceFill(array_merge($this->subscriptionIdentifierUpdates($payload), [
            'status' => Subscription::STATUS_PAST_DUE,
            'failed_at' => $occurredAt,
        ]))->save();

        return new WebhookHandlingResult($payload, $subscription->refresh(), $transaction);
    }

    /**
     * Record or update a transaction for a webhook payload.
     */
    protected function recordTransaction(Subscription $subscription, WebhookPayload $payload, string $status, Carbon $occurredAt): ?Transaction
    {
        $transactionModel = CashierNets::$transactionModel;
        $rawPayload = $payload->raw();
        $chargeId = $payload->chargeId();
        $paymentId = $payload->paymentId();

        if ($chargeId === null && $paymentId === null) {
            return null;
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
            'nets_subscription_id' => $payload->subscriptionId() ?? $subscription->nets_subscription_id,
            'nets_unscheduled_subscription_id' => $subscription->nets_unscheduled_subscription_id,
            'status' => $status,
            'amount' => $payload->amount(),
            'currency' => $payload->currency(),
            'failure_code' => $status === Transaction::STATUS_FAILED ? $this->stringValue(Arr::get($rawPayload, 'data.error.code')) : null,
            'failure_message' => $status === Transaction::STATUS_FAILED ? $this->stringValue(Arr::get($rawPayload, 'data.error.message')) : null,
            'billed_at' => $occurredAt,
            'metadata' => array_merge($transaction->metadata ?? [], array_filter([
                'webhook_event_id' => $payload->eventId(),
                'webhook_event_name' => $payload->eventName(),
                'reconciliation_reference' => $this->stringValue(Arr::get($rawPayload, 'data.reconciliationReference')),
                'my_reference' => $this->stringValue(Arr::get($rawPayload, 'data.myReference')),
                'invoice_number' => $this->invoiceNumber($rawPayload),
            ], fn (?string $value): bool => $value !== null)),
        ])->save();

        return $transaction;
    }

    /**
     * Find the local subscription that belongs to the webhook payload.
     */
    protected function findSubscription(WebhookPayload $payload): ?Subscription
    {
        $subscriptionModel = CashierNets::$subscriptionModel;

        $subscriptionId = $payload->subscriptionId();

        if ($subscriptionId !== null) {
            $subscription = $subscriptionModel::query()
                ->where('nets_subscription_id', $subscriptionId)
                ->first();

            if ($subscription) {
                return $subscription;
            }
        }

        $paymentId = $payload->paymentId();

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
     * @return array<string, string>
     */
    protected function subscriptionIdentifierUpdates(WebhookPayload $payload): array
    {
        $subscriptionId = $payload->subscriptionId();

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
        return WebhookPayload::from($payload)->eventId();
    }

    /**
     * Get the webhook event name.
     *
     * @param  array<string, mixed>  $payload
     */
    public function eventName(array $payload): string
    {
        return WebhookPayload::from($payload)->eventName();
    }

    /**
     * Get the event occurrence time.
     */
    protected function occurredAt(WebhookPayload $payload): Carbon
    {
        $occurredAt = $payload->occurredAt();

        return $occurredAt === null ? Carbon::now() : Carbon::instance($occurredAt);
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

    /**
     * Extract a provider invoice number from known Nets payload shapes.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function invoiceNumber(array $payload): ?string
    {
        foreach ([
            'data.invoiceNumber',
            'data.invoice.invoiceNumber',
            'data.charge.invoiceNumber',
            'data.payment.invoiceNumber',
            'data.payment.paymentDetails.invoiceDetails.invoiceNumber',
            'data.order.invoiceNumber',
        ] as $path) {
            $value = $this->stringValue(Arr::get($payload, $path));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
