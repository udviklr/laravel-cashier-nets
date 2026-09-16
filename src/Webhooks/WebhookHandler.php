<?php

namespace Udviklr\CashierNets\Webhooks;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Charges\ChargeFinalizer;
use Udviklr\CashierNets\Charges\ChargeOutcome;
use Udviklr\CashierNets\Charges\ChargeReconciler;
use Udviklr\CashierNets\Refund;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\WebhookEvent;

class WebhookHandler
{
    /**
     * Handle the given Nets webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, ?WebhookEvent $event = null, ?array $payment = null): WebhookHandlingResult
    {
        $payload = WebhookPayload::from($payload);
        if ($payment !== null) {
            return $this->handleVerifiedPayment($payload, $event, $payment);
        }

        return match ($payload->eventName()) {
            'payment.created' => $this->handlePaymentCreated($payload),
            'payment.checkout.completed' => $this->handleCheckoutCompleted($payload),
            'payment.charge.created', 'payment.charge.created.v2' => $this->handleCharge($payload, Transaction::STATUS_SUCCEEDED, $event),
            'payment.charge.failed', 'payment.charge.failed.v2', 'payment.reservation.failed' => $this->handleCharge($payload, Transaction::STATUS_FAILED, $event),
            'payment.refund.initiated' => $this->handleRefund($payload, Refund::STATUS_PENDING),
            'payment.refund.completed' => $this->handleRefund($payload, Refund::STATUS_COMPLETED),
            'payment.refund.failed' => $this->handleRefund($payload, Refund::STATUS_FAILED),
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
     * Fetch renewal identity before the controller takes any processing locks.
     * Checkout and refund flows retain their existing provider-ID matching.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public function prepare(array $raw): ?array
    {
        $payload = WebhookPayload::from($raw);
        if (! in_array($payload->eventName(), ['payment.created', 'payment.charge.created', 'payment.charge.created.v2',
            'payment.charge.failed', 'payment.charge.failed.v2', 'payment.reservation.failed'], true) || $payload->paymentId() === null) {
            return null;
        }
        $subscription = $this->findSubscription($payload);
        $known = CashierNets::transactionModel()->newQuery()->where(function ($query) use ($payload): void {
            $query->where('nets_payment_id', $payload->paymentId());
            if ($payload->chargeId() !== null) {
                $query->orWhere('nets_charge_id', $payload->chargeId());
            }
        })->first();
        if ($known !== null && $known->frozen_order !== null) {
            return app(ChargeReconciler::class)->retrievePayment($payload->paymentId());
        }
        if ($subscription !== null && $subscription->nets_payment_id === $payload->paymentId()) {
            return null;
        }
        if ($known !== null && ! CashierNets::transactionModel()->newQuery()->whereNotNull('frozen_order')
            ->where('nets_subscription_id', $known->nets_subscription_id)->where('status', Transaction::STATUS_PENDING)->exists()) {
            return null;
        }
        $attempts = CashierNets::transactionModel()->newQuery()->whereNotNull('frozen_order');
        $subscriptionId = null;
        if ($subscription !== null) {
            $subscriptionId = $subscription->nets_subscription_id;
        } elseif ($known !== null) {
            $subscriptionId = $known->nets_subscription_id;
        }
        if ($subscriptionId !== null) {
            $attempts->where('nets_subscription_id', $subscriptionId);
        }

        return $attempts->exists() ? app(ChargeReconciler::class)->retrievePayment($payload->paymentId()) : null;
    }

    /** @param array<string, mixed> $payment */
    protected function handleVerifiedPayment(WebhookPayload $payload, ?WebhookEvent $event, array $payment): WebhookHandlingResult
    {
        $reconciler = app(ChargeReconciler::class);
        $subscriptionId = Arr::get($payment, 'subscription.id');
        if ($payload->subscriptionId() !== null && $payload->subscriptionId() !== $subscriptionId) {
            throw new InvalidArgumentException('Webhook and retrieved payment subscription IDs differ.');
        }
        $row = $reconciler->findAttempt($payment);
        if ($row !== null) {
            return app(ChargeFinalizer::class)->withLockedAttempt($row, function (Transaction $row, Subscription $subscription) use ($payment, $payload, $event, $reconciler): WebhookHandlingResult {
                $reconciler->validateIdentity($row, $payment);
                if ($payload->eventName() === 'payment.created') {
                    $row = app(ChargeFinalizer::class)->enrich($row, $payment['paymentId']);
                } else {
                    $succeeded = in_array($payload->eventName(), ['payment.charge.created', 'payment.charge.created.v2'], true);
                    if (($succeeded && $payload->chargeId() === null)
                        || ($payload->chargeId() !== null && $reconciler->chargeFromPayment($payment, $payload->chargeId(), $row->amount) === null)) {
                        throw new InvalidArgumentException('The webhook charge is not present in the verified payment.');
                    }
                    if (($payload->amount() !== null && $payload->amount() !== $row->amount)
                        || ($payload->currency() !== null && $payload->currency() !== $row->currency)) {
                        throw new InvalidArgumentException('The webhook amount or currency differs from the attempt.');
                    }
                    $row = $this->finalizeWebhookCharge($row, $payload, $succeeded ? Transaction::STATUS_SUCCEEDED : Transaction::STATUS_FAILED, $event);
                }

                return new WebhookHandlingResult($payload, $subscription->refresh(), $row, false);
            });
        }

        // Successful retrieval with no reserved attempt is the legacy fallback.
        // Do not let a changed reference bypass validation of an identified row.
        $known = CashierNets::transactionModel()->newQuery()->whereNotNull('frozen_order')
            ->where('nets_payment_id', $payment['paymentId'])->exists();
        if ($known) {
            throw new InvalidArgumentException('The identified attempt does not match this payment reference.');
        }
        $subscription = is_string($subscriptionId)
            ? CashierNets::subscriptionModel()->newQuery()->where('nets_subscription_id', $subscriptionId)->first() : null;
        if ($subscription !== null && $subscription->transactions()->where('status', Transaction::STATUS_PENDING)->whereNull('nets_charge_id')->exists()) {
            Log::warning('Cashier Nets received a payment without a matching attempt reference while the subscription has unresolved charges.', [
                'subscription_id' => $subscription->getKey(), 'payment_id' => $payment['paymentId'],
            ]);
        }
        if ($payload->eventName() === 'payment.created') {
            return new WebhookHandlingResult($payload, $subscription);
        }

        return $this->handleCharge($payload, in_array($payload->eventName(), ['payment.charge.created', 'payment.charge.created.v2'], true)
            ? Transaction::STATUS_SUCCEEDED : Transaction::STATUS_FAILED, $event, $subscription);
    }

    protected function handleCharge(WebhookPayload $payload, string $status, ?WebhookEvent $event, ?Subscription $subscription = null): WebhookHandlingResult
    {
        $subscription = $subscription ?? $this->findSubscription($payload);
        if ($subscription === null || ($payload->chargeId() === null && $payload->paymentId() === null)) {
            return new WebhookHandlingResult($payload, $subscription);
        }

        return $subscription->getConnection()->transaction(function () use ($subscription, $payload, $status, $event): WebhookHandlingResult {
            $query = $subscription->newQuery();
            $query->lockForUpdate();
            $subscription = $query->findOrFail($subscription->getKey());
            // A checkout charge can arrive before checkout.completed and be
            // the first event to supply its subscription identifier.
            if ($subscription->nets_subscription_id === null && $payload->subscriptionId() !== null) {
                $subscription->forceFill($this->subscriptionIdentifierUpdates($payload))->save();
            }
            $row = $this->recordTransaction($subscription, $payload);
            $row = $this->finalizeWebhookCharge($row, $payload, $status, $event);

            return new WebhookHandlingResult($payload, $subscription->refresh(), $row, false);
        });
    }

    protected function finalizeWebhookCharge(Transaction $row, WebhookPayload $payload, string $status, ?WebhookEvent $event): Transaction
    {
        $failure = $this->errorFields($payload);
        $metadata = array_merge($this->webhookEventMetadata($payload), $this->referenceMetadata($payload->raw()));
        $source = $this->stringValue(Arr::get($payload->raw(), 'data.error.source'));
        if ($source !== null) {
            $metadata['failure_source'] = $source;
        }

        return app(ChargeFinalizer::class)->finalizeCharge($row, new ChargeOutcome($status,
            $payload->paymentId(), $payload->chargeId(), $payload->occurredAt(), 'webhook',
            $status === Transaction::STATUS_FAILED ? $failure['failure_code'] : null,
            $status === Transaction::STATUS_FAILED ? $failure['failure_message'] : null,
            $payload->raw(), $metadata, $event, $payload));
    }

    /**
     * Handle a refund lifecycle event.
     */
    protected function handleRefund(WebhookPayload $payload, string $status): WebhookHandlingResult
    {
        $transaction = $this->resolveChargeTransaction($payload);
        $subscription = $this->resolveSubscription($payload, $transaction);

        // A refund event without an identifier cannot be tracked locally.
        if ($payload->refundId() === null) {
            return new WebhookHandlingResult($payload, $subscription, $transaction);
        }

        $refund = $this->recordRefund($payload, $status, $transaction);

        // Use the persisted status: a late event that could not regress a
        // terminal refund must not drive the transaction either.
        if ($refund->status === Refund::STATUS_COMPLETED && $transaction !== null) {
            $this->markTransactionRefundedIfFullyRefunded($transaction);
        }

        return new WebhookHandlingResult($payload, $subscription, $transaction);
    }

    /**
     * Record or update a refund row for a refund webhook payload.
     */
    protected function recordRefund(WebhookPayload $payload, string $status, ?Transaction $transaction): Refund
    {
        $refundModel = CashierNets::$refundModel;

        $refund = $refundModel::query()->where('nets_refund_id', $payload->refundId())->first();

        if ($refund === null) {
            // A refund initiated locally is keyed by its idempotency key and only
            // learns its nets_refund_id from the API response; if that response
            // never landed (e.g. a timeout) the row still has a null nets_refund_id.
            // Adopt that pending row instead of inserting a duplicate, which would
            // double-count the reservation and corrupt the refunded total.
            $refund = $this->adoptPendingRefund($payload, $transaction)
                ?? $refundModel::query()->firstOrNew(['nets_refund_id' => $payload->refundId()]);
        }

        // Refund webhooks are not guaranteed to arrive in order. Completion is the
        // strongest terminal state (the money moved) and must win over a prior
        // failure, while neither a late "initiated" nor a stray "failed" may
        // regress a completed refund. Ranking the states keeps deliveries monotonic.
        $applyStatus = ! $refund->exists
            || $this->refundStatusRank($status) >= $this->refundStatusRank($refund->status);

        // Identifiers are filled in but never overwritten by a later event: prefer
        // the value already on the row and fall back to the payload to backfill a
        // gap (completed/failed events omit chargeId, so the row may already hold
        // the only copy of it).
        $attributes = [
            'nets_refund_id' => $payload->refundId(),
            'nets_charge_id' => $refund->nets_charge_id ?? $payload->chargeId(),
            'nets_payment_id' => $refund->nets_payment_id ?? $payload->paymentId(),
        ];

        if ($applyStatus) {
            $attributes['status'] = $status;
        }

        // Link the refund only to the succeeded charge it is refunding;
        // resolveChargeTransaction may fall back to a non-succeeded row (used for
        // subscription/event context), which must not become the refund's billable.
        if ($transaction !== null && $transaction->status === Transaction::STATUS_SUCCEEDED) {
            $attributes['billable_type'] = $transaction->billable_type;
            $attributes['billable_id'] = $transaction->billable_id;
            $attributes['nets_transaction_id'] = $transaction->getKey();
            $attributes['nets_charge_id'] = $transaction->nets_charge_id ?? $attributes['nets_charge_id'];
            $attributes['nets_payment_id'] = $transaction->nets_payment_id ?? $attributes['nets_payment_id'];
        }

        // Financial fields and the failure reason are only written by the event
        // that wins the status race: a late delivery that cannot advance the status
        // must not rewrite the amount, currency, or failure of a row that already
        // moved past it (e.g. a stray "failed" carrying amount 0 must not zero a
        // completed refund). A still-null value is backfilled regardless, so an
        // out-of-order event can seed a field a prior one lacked.
        $amount = $payload->amount();

        if ($amount !== null && ($applyStatus || $refund->amount === null)) {
            $attributes['amount'] = $amount;
        }

        $currency = $payload->currency();

        if ($currency !== null && ($applyStatus || $refund->currency === null)) {
            $attributes['currency'] = $currency;
        }

        if ($applyStatus && $status === Refund::STATUS_FAILED) {
            $attributes = array_merge($attributes, $this->errorFields($payload));
        } elseif ($applyStatus && $status === Refund::STATUS_COMPLETED) {
            // Clear any failure recorded by an earlier event the completion supersedes.
            $attributes['failure_code'] = null;
            $attributes['failure_message'] = null;
        }

        $attributes['metadata'] = array_merge(
            $refund->metadata ?? [],
            $this->webhookEventMetadata($payload),
            $this->referenceMetadata($payload->raw()),
        );

        $refund->forceFill($attributes)->save();

        return $refund;
    }

    /**
     * Adopt a locally-initiated pending refund row that has no nets_refund_id yet.
     *
     * Only an unambiguous single match is adopted, so an incoming webhook never
     * attaches its refund id to the wrong pending attempt for the charge.
     */
    protected function adoptPendingRefund(WebhookPayload $payload, ?Transaction $transaction): ?Refund
    {
        $chargeId = $payload->chargeId() ?? $transaction?->nets_charge_id;

        if ($chargeId === null) {
            return null;
        }

        $refundModel = CashierNets::$refundModel;
        $amount = $payload->amount();

        $query = $refundModel::query()
            ->where('nets_charge_id', $chargeId)
            ->where('status', Refund::STATUS_PENDING);

        // Match the amount when the event carries one, so a refund id is never
        // attached to a pending attempt recorded for a different amount.
        if ($amount !== null) {
            $query->where('amount', $amount);
        }

        // Only attempts that have not yet learned their nets_refund_id are adoptable.
        $candidates = $query->get()
            ->filter(fn (Refund $refund): bool => $refund->nets_refund_id === null)
            ->values();

        // Only an unambiguous single match is adopted, so an incoming webhook never
        // attaches its refund id to the wrong pending attempt for the charge.
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Rank a refund status so out-of-order webhook deliveries stay monotonic.
     */
    protected function refundStatusRank(?string $status): int
    {
        return match ($status) {
            Refund::STATUS_COMPLETED => 2,
            Refund::STATUS_FAILED => 1,
            default => 0,
        };
    }

    /**
     * Flip the charge transaction to refunded once its refunds cover the charge.
     */
    protected function markTransactionRefundedIfFullyRefunded(Transaction $transaction): void
    {
        // Only a succeeded charge can be refunded; never flip a failed, pending,
        // canceled, or already-refunded transaction (resolveChargeTransaction may
        // fall back to a non-succeeded row when resolving the charge).
        if ($transaction->nets_charge_id === null || $transaction->status !== Transaction::STATUS_SUCCEEDED) {
            return;
        }

        // Serialize concurrent refund completions for this charge: lock the charge
        // row so each completion waits for the others to commit, then re-read the
        // committed state under the lock before summing. Two partial completions
        // that together cover the charge cannot both decide coverage is incomplete
        // and leave the charge stuck succeeded.
        $transaction->newQuery()->whereKey($transaction->getKey())->lockForUpdate()->first();
        $transaction->refresh();

        if ($transaction->status !== Transaction::STATUS_SUCCEEDED) {
            return;
        }

        $chargeAmount = $transaction->rawAmount();

        // Without a known positive charge amount, full coverage cannot be
        // proven, so the transaction is left untouched rather than being
        // spuriously marked refunded by a zero-amount comparison.
        if ($chargeAmount <= 0) {
            return;
        }

        $refundModel = CashierNets::$refundModel;

        $completed = (int) $refundModel::query()
            ->where('nets_charge_id', $transaction->nets_charge_id)
            ->where('status', Refund::STATUS_COMPLETED)
            ->sum('amount');

        if ($completed >= $chargeAmount) {
            $transaction->forceFill(['status' => Transaction::STATUS_REFUNDED])->save();
        }
    }

    /**
     * Find the succeeded charge transaction for a charge identifier.
     */
    protected function findTransactionByCharge(?string $chargeId): ?Transaction
    {
        if ($chargeId === null) {
            return null;
        }

        $transactionModel = CashierNets::$transactionModel;

        $query = $transactionModel::query()->where('nets_charge_id', $chargeId);

        return (clone $query)->where('status', Transaction::STATUS_SUCCEEDED)->first()
            ?? $query->first();
    }

    /**
     * Find the charge transaction for a payment identifier, preferring a succeeded row.
     */
    protected function findTransactionByPayment(?string $paymentId): ?Transaction
    {
        if ($paymentId === null) {
            return null;
        }

        $transactionModel = CashierNets::$transactionModel;

        $query = $transactionModel::query()->where('nets_payment_id', $paymentId);

        return (clone $query)->where('status', Transaction::STATUS_SUCCEEDED)->first()
            ?? $query->first();
    }

    /**
     * Resolve the charge transaction a refund event belongs to.
     *
     * Only payment.refund.initiated carries a chargeId; the completed and failed
     * events omit it and carry only the paymentId. The charge is therefore
     * recovered from, in order: the event chargeId, the chargeId already stored on
     * a locally recorded refund row for this refundId, then the paymentId — so a
     * completion can still resolve its charge and flip it to refunded.
     */
    protected function resolveChargeTransaction(WebhookPayload $payload): ?Transaction
    {
        $chargeId = $payload->chargeId();

        if ($chargeId === null && $payload->refundId() !== null) {
            $refundModel = CashierNets::$refundModel;

            $chargeId = $refundModel::query()
                ->where('nets_refund_id', $payload->refundId())
                ->value('nets_charge_id');
        }

        return $this->findTransactionByCharge($chargeId)
            ?? $this->findTransactionByPayment($payload->paymentId());
    }

    /**
     * Resolve the subscription for a refund, falling back to the charge transaction.
     */
    protected function resolveSubscription(WebhookPayload $payload, ?Transaction $transaction): ?Subscription
    {
        $subscription = $this->findSubscription($payload);

        if ($subscription !== null) {
            return $subscription;
        }

        if ($transaction === null || $transaction->nets_subscription_id === null) {
            return null;
        }

        $subscriptionModel = CashierNets::$subscriptionModel;

        return $subscriptionModel::query()
            ->where('nets_subscription_id', $transaction->nets_subscription_id)
            ->first();
    }

    /**
     * Record or update a transaction for a webhook payload.
     */
    protected function recordTransaction(Subscription $subscription, WebhookPayload $payload): Transaction
    {
        $query = CashierNets::transactionModel()->newQuery();
        $row = $payload->chargeId() !== null
            ? $query->where('nets_charge_id', $payload->chargeId())->first()
            : $query->where('nets_payment_id', $payload->paymentId())->first();
        if ($row !== null) {
            if (($row->nets_subscription_id !== null && $row->nets_subscription_id !== $subscription->nets_subscription_id)
                || $row->billable_type !== $subscription->billable_type || (string) $row->billable_id !== (string) $subscription->billable_id) {
                throw new InvalidArgumentException('Webhook transaction belongs to another subscription.');
            }
            if ($row->frozen_order !== null) {
                throw new InvalidArgumentException('A reserved attempt requires retrieved payment validation.');
            }
            $row->nets_subscription_id = $row->nets_subscription_id ?? $subscription->nets_subscription_id;
            if ($payload->amount() !== null && ($row->status === Transaction::STATUS_PENDING || $row->amount === null)) {
                $row->amount = $payload->amount();
            }
            if ($payload->currency() !== null && ($row->status === Transaction::STATUS_PENDING || $row->currency === null)) {
                $row->currency = $payload->currency();
            }
            $row->save();

            return $row;
        }

        return CashierNets::transactionModel()->newQuery()->create([
            'billable_type' => $subscription->billable_type, 'billable_id' => $subscription->billable_id,
            'nets_payment_id' => $payload->paymentId(), 'nets_charge_id' => $payload->chargeId(),
            'nets_subscription_id' => $subscription->nets_subscription_id,
            'nets_unscheduled_subscription_id' => $subscription->nets_unscheduled_subscription_id,
            'status' => Transaction::STATUS_PENDING, 'amount' => $payload->amount(), 'currency' => $payload->currency(),
        ]);
    }

    /**
     * Extract the Nets error code and message from a failed webhook payload.
     *
     * @return array{failure_code: string|null, failure_message: string|null}
     */
    protected function errorFields(WebhookPayload $payload): array
    {
        $rawPayload = $payload->raw();

        return [
            'failure_code' => $this->stringValue(Arr::get($rawPayload, 'data.error.code')),
            'failure_message' => $this->stringValue(Arr::get($rawPayload, 'data.error.message')),
        ];
    }

    /**
     * Build the shared webhook-event metadata recorded on transactions and refunds.
     *
     * @return array<string, string>
     */
    protected function webhookEventMetadata(WebhookPayload $payload): array
    {
        return array_filter([
            'webhook_event_id' => $payload->eventId(),
            'webhook_event_name' => $payload->eventName(),
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * Build the reconciliation metadata Nets exposes on charges and refunds.
     *
     * Refund webhooks carry reconciliationReference / myReference / invoiceNumber
     * just as charge webhooks do, so both transaction and refund rows record them.
     *
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, string>
     */
    protected function referenceMetadata(array $rawPayload): array
    {
        return array_filter([
            'reconciliation_reference' => $this->stringValue(Arr::get($rawPayload, 'data.reconciliationReference')),
            'my_reference' => $this->stringValue(Arr::get($rawPayload, 'data.myReference')),
            'invoice_number' => $this->invoiceNumber($rawPayload),
        ], fn (?string $value): bool => $value !== null);
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
