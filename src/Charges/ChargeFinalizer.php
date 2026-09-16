<?php

namespace Udviklr\CashierNets\Charges;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Events\ChargeAttemptFailed;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeOutcomeUncertain;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\Webhooks\WebhookPayload;

class ChargeFinalizer
{
    /**
     * Every charge writer takes the subscription lock first, then reloads the
     * attempt. Provider requests must finish before entering this method.
     *
     * @template T
     *
     * @param  callable(Transaction, Subscription): T  $callback
     * @return T
     */
    public function withLockedAttempt(Transaction $row, callable $callback): mixed
    {
        return $row->getConnection()->transaction(function () use ($row, $callback) {
            $query = CashierNets::subscriptionModel()->newQuery();
            $query->where('billable_type', $row->billable_type)->where('billable_id', $row->billable_id);
            if ($row->nets_subscription_id !== null) {
                $query->where('nets_subscription_id', $row->nets_subscription_id);
            } elseif ($row->nets_payment_id !== null) {
                $query->where('nets_payment_id', $row->nets_payment_id);
            } else {
                throw new InvalidArgumentException('The attempt has no subscription or checkout payment identity.');
            }
            $query->lockForUpdate();
            $subscription = $query->firstOrFail();
            $attemptQuery = $row->newQuery();
            $attemptQuery->lockForUpdate();
            $locked = $attemptQuery->findOrFail($row->getKey());

            return $callback($locked, $subscription);
        });
    }

    public function finalizeCharge(Transaction $row, ChargeOutcome $outcome): Transaction
    {
        if (! in_array($outcome->status, [Transaction::STATUS_SUCCEEDED, Transaction::STATUS_FAILED], true)) {
            throw new InvalidArgumentException('Finalization requires a terminal charge outcome.');
        }

        return $this->withLockedAttempt($row, function (Transaction $row, Subscription $subscription) use ($outcome): Transaction {
            $canonical = $this->applyIdentity($row, $outcome->paymentId, $outcome->chargeId);
            if (! $canonical->is($row)) {
                return $canonical;
            }

            $metadata = $this->enrichMetadata($row, $outcome->metadata);
            $this->enrichFailure($row, $outcome->failureCode, $outcome->failureMessage, $metadata);
            $row->metadata = $metadata;
            if ($row->status !== Transaction::STATUS_PENDING) {
                $row->save();

                return $row;
            }

            $occurredAt = $outcome->providerOccurredAt ?? now();
            if ($outcome->providerOccurredAt === null) {
                $row->metadata = array_merge($row->metadata ?? [], ['provider_timestamp_missing' => true]);
            }
            $row->forceFill(['status' => $outcome->status, 'billed_at' => $occurredAt, 'uncertain_at' => null])->save();

            if ($outcome->status === Transaction::STATUS_SUCCEEDED) {
                $updates = ['status' => Subscription::STATUS_ACTIVE, 'last_charged_at' => $occurredAt, 'failed_at' => null];
                if ($subscription->interval_days !== null) {
                    $updates['next_charge_at'] = $occurredAt->copy()->addDays((int) $subscription->interval_days);
                }
            } else {
                $updates = ['status' => Subscription::STATUS_PAST_DUE, 'failed_at' => $occurredAt];
            }
            $subscription->forceFill($updates)->save();

            $event = $outcome->webhookEvent;
            $payload = $outcome->webhookPayload;
            if ($event === null || $payload === null) {
                $eventId = $outcome->source.':'.$row->getKey().':'.($outcome->chargeId ?? $outcome->paymentId);
                $payload = WebhookPayload::fromChargeOutcome($row, $outcome, $eventId, $occurredAt);
                $eventModel = CashierNets::$webhookEventModel;
                $event = $eventModel::query()->firstOrCreate(['nets_event_id' => $eventId], [
                    'event_name' => $payload->eventName(), 'payload' => $payload->raw(), 'source' => $outcome->source,
                ]);
            }

            $class = $outcome->status === Transaction::STATUS_SUCCEEDED ? ChargeSucceeded::class : ChargeFailed::class;
            event(new $class($payload, $event, $subscription, $row));
            if ($outcome->source !== 'webhook') {
                $event->forceFill(['processed_at' => now()])->save();
            }

            return $row;
        });
    }

    /** @param array<string, mixed> $metadata */
    public function enrich(Transaction $row, ?string $paymentId, ?string $chargeId = null, array $metadata = []): Transaction
    {
        return $this->withLockedAttempt($row, function (Transaction $row) use ($paymentId, $chargeId, $metadata): Transaction {
            $canonical = $this->applyIdentity($row, $paymentId, $chargeId);
            if ($canonical->is($row)) {
                $row->metadata = $this->enrichMetadata($row, $metadata);
                $row->save();
            }

            return $canonical;
        });
    }

    /** @param array<string, mixed> $metadata */
    public function recordFailure(Transaction $row, Throwable $exception, ?string $code, array $metadata = []): Transaction
    {
        return $this->withLockedAttempt($row, function (Transaction $row, Subscription $subscription) use ($exception, $code, $metadata): Transaction {
            if ($row->status !== Transaction::STATUS_PENDING) {
                return $row;
            }
            $row->forceFill([
                'status' => Transaction::STATUS_FAILED, 'uncertain_at' => null,
                'failure_code' => $code, 'failure_message' => $exception->getMessage(), 'billed_at' => now(),
                'metadata' => array_merge($row->metadata ?? [], $metadata),
            ])->save();
            $subscription->forceFill(['status' => Subscription::STATUS_PAST_DUE, 'failed_at' => now()])->save();
            event(new ChargeAttemptFailed($subscription, $row, $exception));

            return $row;
        });
    }

    /** @param array<string, mixed> $metadata */
    public function markUncertain(Transaction $row, Throwable $exception, array $metadata): Transaction
    {
        return $this->withLockedAttempt($row, function (Transaction $row, Subscription $subscription) use ($exception, $metadata): Transaction {
            if ($row->status !== Transaction::STATUS_PENDING || $row->nets_charge_id !== null) {
                return $row;
            }
            $alreadyUncertain = $row->uncertain_at !== null;
            $row->forceFill(['uncertain_at' => $row->uncertain_at ?? now(), 'metadata' => array_merge($row->metadata ?? [], $metadata)])->save();
            if (! $alreadyUncertain) {
                event(new ChargeOutcomeUncertain($subscription, $row, $exception));
            }

            return $row;
        });
    }

    public function confirmFailed(Transaction $row, ?string $reason): Transaction
    {
        return $this->withLockedAttempt($row, function (Transaction $row) use ($reason): Transaction {
            if ($row->status === Transaction::STATUS_PENDING && $row->nets_charge_id !== null) {
                throw new InvalidArgumentException('Cannot mark this attempt failed while it has a charge ID; reconcile the provider outcome instead.');
            }

            return $this->recordFailure($row, new RuntimeException($reason ?? 'Operator confirmed that no charge exists for this attempt.'),
                ($row->metadata['failure_code_source'] ?? null) === 'http' ? null : $row->failure_code, [
                    'operator_confirmed_at' => now()->toIso8601String(), 'operator_reason' => $reason,
                    'provider_timestamp_missing' => true,
                ]);
        });
    }

    /** Must be called under the subscription lock. */
    protected function applyIdentity(Transaction $row, ?string $paymentId, ?string $chargeId): Transaction
    {
        if (($row->nets_payment_id !== null && $paymentId !== null && $row->nets_payment_id !== $paymentId)
            || ($row->nets_charge_id !== null && $chargeId !== null && $row->nets_charge_id !== $chargeId)) {
            throw new InvalidArgumentException('Provider identifiers conflict with the recorded attempt.');
        }
        if ($chargeId !== null) {
            $owner = $row->newQuery()->where('nets_charge_id', $chargeId)->first();
            if ($owner !== null && ! $owner->is($row)) {
                if ($owner->nets_subscription_id !== $row->nets_subscription_id || $owner->billable_type !== $row->billable_type
                    || (string) $owner->billable_id !== (string) $row->billable_id) {
                    throw new InvalidArgumentException('The charge belongs to another subscription.');
                }
                if ($row->status === Transaction::STATUS_PENDING) {
                    $row->forceFill(['status' => Transaction::STATUS_CANCELED, 'uncertain_at' => null,
                        'metadata' => array_merge($row->metadata ?? [], ['superseded_by' => $owner->getKey()])])->save();
                }

                return $owner;
            }
        }
        $row->nets_payment_id = $row->nets_payment_id ?? $paymentId;
        $row->nets_charge_id = $row->nets_charge_id ?? $chargeId;
        if ($row->nets_charge_id !== null) {
            $row->uncertain_at = null;
        }

        return $row;
    }

    /**
     * Preserve the request snapshot and fill only missing provider metadata.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    protected function enrichMetadata(Transaction $row, array $incoming): array
    {
        $metadata = $row->metadata ?? [];
        foreach ($incoming as $key => $value) {
            if (! isset($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    protected function enrichFailure(Transaction $row, ?string $code, ?string $message, array &$metadata): void
    {
        $provenance = $row->metadata['failure_code_source'] ?? null;
        $fallback = $provenance === 'http' || ($provenance === null && preg_match('/^4[0-9]{2}$/', $row->failure_code ?? '') === 1);
        if ($code !== null && ($row->failure_code === null || $fallback)) {
            $row->failure_code = $code;
            $row->failure_message = $message ?? $row->failure_message;
            $metadata['failure_code_source'] = 'provider';
        } elseif ($row->failure_message === null && $message !== null) {
            $row->failure_message = $message;
        }
    }
}
