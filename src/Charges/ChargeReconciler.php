<?php

namespace Udviklr\CashierNets\Charges;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Events\ChargeReconciliationStalled;
use Udviklr\CashierNets\Exceptions\NetsException;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

class ChargeReconciler
{
    public function __construct(protected ChargeFinalizer $finalizer) {}

    /** @return array<string, mixed> */
    public function retrievePayment(string $paymentId): array
    {
        $response = CashierNets::api('GET', 'v1/payments/'.rawurlencode($paymentId));
        $payment = $response->json('payment');
        if (! $response->successful() || ! is_array($payment) || ($payment['paymentId'] ?? null) !== $paymentId) {
            throw new InvalidArgumentException('The retrieved payment does not match the requested payment ID.');
        }

        return $payment;
    }

    /** @param array<string, mixed> $payment */
    public function validateIdentity(Transaction $row, array $payment): void
    {
        $order = $row->frozen_order;
        $expectedPayment = $row->nets_payment_id;
        $matches = is_string($payment['paymentId'] ?? null)
            && ($expectedPayment === null || $expectedPayment === $payment['paymentId'])
            && Arr::get($payment, 'subscription.id') === $row->nets_subscription_id
            && $this->sameAmount(Arr::get($payment, 'orderDetails.amount'), $order['amount'] ?? $row->amount)
            && Arr::get($payment, 'orderDetails.currency') === ($order['currency'] ?? $row->currency);
        // Old provider-ID-based rows have no attempt snapshot. Never retrofit a
        // key: only their already recorded payment can be verified here.
        $matches = $matches && ($order !== null
            ? Arr::get($payment, 'orderDetails.reference') === $row->idempotency_key
            : $expectedPayment !== null);
        if (! $matches) {
            throw new InvalidArgumentException('Payment identity does not match the subscription, attempt key, amount and currency.');
        }
    }

    /** @param array<string, mixed> $payment */
    public function findAttempt(array $payment): ?Transaction
    {
        $key = Arr::get($payment, 'orderDetails.reference');
        $subscriptionId = Arr::get($payment, 'subscription.id');
        if (! is_string($key) || $key === '' || ! is_string($subscriptionId)) {
            return null;
        }
        $query = CashierNets::transactionModel()->newQuery();
        $query->whereNotNull('frozen_order')->where('idempotency_key', $key)->where('nets_subscription_id', $subscriptionId);
        $row = $query->first();
        if ($row !== null) {
            $this->validateIdentity($row, $payment);
        }

        return $row;
    }

    /** @param array<string, mixed> $payment
     * @return array<string, mixed>|null
     */
    public function chargeFromPayment(array $payment, ?string $chargeId, ?int $amount): ?array
    {
        $matches = [];
        foreach (($payment['charges'] ?? []) as $charge) {
            if (is_array($charge) && self::stringValue($charge['chargeId'] ?? null) !== null
                && ($chargeId === null || $charge['chargeId'] === $chargeId)
                && $this->sameAmount($charge['amount'] ?? null, $amount)) {
                $matches[] = $charge;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, mixed> $payment */
    public function outcomeFromPayment(Transaction $row, array $payment, string $source, ?string $chargeId = null): ?ChargeOutcome
    {
        $this->validateIdentity($row, $payment);
        $charge = $this->chargeFromPayment($payment, $chargeId ?? $row->nets_charge_id, $row->amount);
        if ($charge === null) {
            return null;
        }
        $state = $charge['status'] ?? null;
        $code = self::stringValue(Arr::get($charge, 'error.code'));
        if (in_array($state, ['failed', 'declined'], true) && $code !== null) {
            return new ChargeOutcome(Transaction::STATUS_FAILED, $payment['paymentId'], $charge['chargeId'],
                $this->timestamp($charge['failed'] ?? null), $source, $code,
                self::stringValue(Arr::get($charge, 'error.message')), $payment);
        }
        $charged = Arr::get($payment, 'summary.chargedAmount');
        if (($state === null || in_array($state, ['completed', 'succeeded', 'charged'], true))
            && is_numeric($charged) && (int) $charged >= $row->amount) {
            return new ChargeOutcome(Transaction::STATUS_SUCCEEDED, $payment['paymentId'], $charge['chargeId'],
                $this->timestamp($charge['created'] ?? null), $source, providerData: $payment);
        }

        return null;
    }

    public function reconcile(Transaction $row, string $source = 'reconcile'): Transaction
    {
        $row->refresh();
        if ($row->status !== Transaction::STATUS_PENDING) {
            return $row;
        }
        // Catch only provider reads here. Listener and database failures must
        // propagate so a rolled-back finalization is reported and retried.
        try {
            if ($row->nets_charge_id !== null && $row->nets_payment_id !== null) {
                $payment = $this->retrievePayment($row->nets_payment_id);
                $status = null;
            } elseif ($row->idempotency_key !== null && $row->frozen_order !== null) {
                $response = CashierNets::api('GET', 'v1/subscriptions/'.rawurlencode($row->nets_subscription_id).'/charges/status', null,
                    ['idempotency_key' => $row->idempotency_key]);
                $status = $response->json();
                if (! $response->successful() || ! is_array($status) || self::stringValue($status['paymentId'] ?? null) === null) {
                    return $row;
                }
                $payment = $this->retrievePayment($status['paymentId']);
            } else {
                return $row;
            }
        } catch (ConnectionException) {
            return $row;
        } catch (NetsException $exception) {
            if ($exception->getCode() === 404 || $exception->getCode() >= 500 || in_array($exception->getCode(), [408, 429], true)) {
                return $row;
            }
            throw $exception;
        }
        $this->validateIdentity($row, $payment);
        $chargeId = $status === null ? $row->nets_charge_id : self::stringValue($status['chargeId'] ?? null);
        if ($status === null || (($status['completed'] ?? null) === true && $chargeId !== null)) {
            $outcome = $this->outcomeFromPayment($row, $payment, $source, $chargeId);

            return $outcome === null ? $row : $this->finalizer->finalizeCharge($row, $outcome);
        }
        if (($status['completed'] ?? null) === false) {
            return $this->finalizer->enrich($row, $payment['paymentId'], $chargeId);
        }

        return $row;
    }

    public function resolvePayment(Transaction $row, string $paymentId, bool $failed = false, ?string $reason = null): Transaction
    {
        if ($failed && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('A --reason is required when confirming a declined payment.');
        }
        $payment = $this->retrievePayment($paymentId);
        $this->validateIdentity($row, $payment);
        $outcome = $this->outcomeFromPayment($row, $payment, 'manual');
        if ($outcome !== null) {
            return $this->finalizer->finalizeCharge($row, $outcome);
        }
        $charge = $this->chargeFromPayment($payment, $row->nets_charge_id, $row->amount);
        $row = $this->finalizer->enrich($row, $paymentId, $charge['chargeId'] ?? null);

        return $failed ? $this->finalizer->confirmFailed($row, $reason) : $row;
    }

    public function alertIfStalled(Transaction $row): void
    {
        $this->finalizer->withLockedAttempt($row, function (Transaction $row, Subscription $subscription): void {
            if ($row->status !== Transaction::STATUS_PENDING || (! $row->held() && $row->nets_charge_id === null)
                || $row->created_at === null || $row->created_at->gte(now()->subMinutes(max(0, (int) config('cashier-nets.reconcile.alert_after_minutes', 60))))) {
                return;
            }
            if (isset($row->metadata['reconciliation_stalled_at'])) {
                return;
            }
            $row->forceFill(['metadata' => array_merge($row->metadata ?? [], ['reconciliation_stalled_at' => now()->toIso8601String()])])->save();
            event(new ChargeReconciliationStalled($subscription, $row));
        });
    }

    /** @param array<string, mixed> $body */
    public static function declinePaymentCandidate(array $body, string $message): ?string
    {
        $structured = self::stringValue($body['paymentId'] ?? Arr::get($body, 'error.paymentId'));
        if ($structured !== null) {
            return $structured;
        }
        preg_match_all('/(?<![a-zA-Z0-9_])[a-fA-F0-9]{32}(?![a-zA-Z0-9_])/', $message, $matches);
        $ids = array_values(array_unique($matches[0]));

        return count($ids) === 1 ? $ids[0] : null;
    }

    public static function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    protected function sameAmount(mixed $value, ?int $expected): bool
    {
        return $expected !== null && (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value === $expected;
    }

    protected function timestamp(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
