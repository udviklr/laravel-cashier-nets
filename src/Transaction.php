<?php

namespace Udviklr\CashierNets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Udviklr\CashierNets\Exceptions\RefundException;

/**
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string|null $nets_payment_id
 * @property string|null $nets_charge_id
 * @property string|null $nets_subscription_id
 * @property string|null $idempotency_key
 * @property int|null $amount
 * @property string|null $currency
 * @property string $status
 * @property string|null $failure_code
 * @property \Illuminate\Support\Carbon|null $billed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Transaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELED = 'canceled';

    /**
     * The table associated with the model.
     */
    protected $table = 'nets_transactions';

    /**
     * The attributes that are not mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
        'billed_at' => 'datetime',
    ];

    /**
     * Get the billable model related to the transaction.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the refunds issued against this transaction's charge.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(CashierNets::$refundModel, 'nets_charge_id', 'nets_charge_id');
    }

    /**
     * Get the raw transaction amount in minor units.
     */
    public function rawAmount(): int
    {
        return (int) $this->amount;
    }

    /**
     * Get the formatted transaction amount.
     */
    public function amount(?string $locale = null): string
    {
        return CashierNets::formatAmount($this->rawAmount(), (string) $this->currency, $locale);
    }

    /**
     * Determine if the transaction succeeded.
     */
    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    /**
     * Determine if the transaction failed.
     */
    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Determine if the transaction failure may be retried.
     */
    public function retryable(): bool
    {
        if (! $this->failed()) {
            return false;
        }

        if ($this->failure_code === null) {
            return true;
        }

        return ! in_array($this->failure_code, config('cashier-nets.retry_policy.non_retryable_response_codes', []), true);
    }

    /**
     * Refund this transaction's charge through Nets.
     *
     * A null amount issues a full refund of the charge amount. A smaller amount
     * issues a partial refund; supply VAT-aware $orderItems for a precise line
     * spec, otherwise a single zero-tax line item totalling the amount is sent.
     * Confirmation is asynchronous and arrives via the payment.refund.* webhooks.
     *
     * @param  array<int, array<string, mixed>>  $orderItems
     *
     * @throws \Udviklr\CashierNets\Exceptions\RefundException
     */
    public function refund(?int $amount = null, array $orderItems = [], ?string $idempotencyKey = null): Refund
    {
        $this->ensureRefundable();

        $amount ??= $this->rawAmount();

        if ($amount <= 0) {
            throw new InvalidArgumentException('A refund amount in minor units greater than zero is required.');
        }

        // Serialize concurrent refunds for this charge: the remaining-refundable
        // check and the pending-row insert run under a lock on the charge row so
        // two callers cannot both pass the cap and double-refund. The API call is
        // made after the transaction commits, never while holding the lock.
        [$refund, $idempotencyKey, $items, $alreadySettled] = DB::transaction(function () use ($amount, $orderItems, $idempotencyKey) {
            static::query()->lockForUpdate()->find($this->getKey());

            $idempotencyKey ??= $this->refundIdempotencyKey();

            $refundModel = CashierNets::$refundModel;

            $existing = $refundModel::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            // A terminal refund must never be resurrected under the same key: a
            // completed one already moved the money, so return it unchanged; a
            // failed one needs a fresh key (the generated-key path always gives one).
            if ($existing?->status === Refund::STATUS_COMPLETED) {
                // Reusing the key for a different amount would silently return a
                // refund the caller did not ask for, so reject the mismatch.
                if ($existing->rawAmount() !== $amount) {
                    throw new InvalidArgumentException(
                        'Idempotency key ['.$idempotencyKey.'] already settled a refund of '.$existing->rawAmount().' and cannot be reused for a different amount ('.$amount.').'
                    );
                }

                return [$existing, $idempotencyKey, [], true];
            }

            if ($existing?->status === Refund::STATUS_FAILED) {
                throw new InvalidArgumentException(
                    'A failed refund is already recorded for idempotency key ['.$idempotencyKey.']; issue a new refund with a different key.'
                );
            }

            // A pending row under this key is an in-flight retry of the SAME logical
            // refund; reusing the key for a different amount would rewrite its
            // reservation and disagree with the amount already sent to Nets.
            if ($existing?->status === Refund::STATUS_PENDING && $existing->rawAmount() !== $amount) {
                throw new InvalidArgumentException(
                    'A pending refund of '.$existing->rawAmount().' is already recorded for idempotency key ['.$idempotencyKey.']; retry with the same amount or use a different key.'
                );
            }

            // A retry of the same logical refund reuses its idempotency key, so any
            // in-flight attempt already recorded under that key must not count
            // against the cap (otherwise the retry would be wrongly rejected).
            $remaining = $this->remainingRefundable($idempotencyKey);

            if ($amount > $remaining) {
                throw new InvalidArgumentException(
                    'The refund amount ('.$amount.') exceeds the remaining refundable amount ('.$remaining.').'
                );
            }

            // A full refund omits order items; only a partial refund carries a
            // line spec (caller-supplied $orderItems are ignored for a full refund,
            // which refunds the whole charge and needs no breakdown).
            $items = [];

            if ($amount < $this->rawAmount()) {
                $items = $orderItems !== [] ? array_values($orderItems) : $this->refundOrderItems($amount);

                CashierNets::assertOrderItemsConsistent($items, $amount);
            }

            return [$this->recordPendingRefund($amount, $idempotencyKey), $idempotencyKey, $items, false];
        });

        // The refund already completed under this idempotency key; nothing to send.
        if ($alreadySettled) {
            return $refund;
        }

        try {
            $response = CashierNets::client()->refundCharge(
                (string) $this->nets_charge_id,
                $amount,
                $idempotencyKey,
                $items,
            );
        } catch (RefundException $exception) {
            // Nets returned an error response: the refund was definitively
            // rejected and never processed, so record the failure and release
            // the reserved amount for a fresh attempt. Prefer the Nets error code
            // from the body (the same code space the refund webhook records) so
            // failure_code is consistent across both paths, falling back to the
            // HTTP status only when the body carries no code.
            $body = $exception->body() ?? [];
            $code = Arr::get($body, 'error.code') ?? Arr::get($body, 'code');

            $refund->forceFill([
                'status' => Refund::STATUS_FAILED,
                'failure_code' => is_scalar($code) && (string) $code !== '' ? (string) $code : (string) $exception->getCode(),
                'failure_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        // Any other failure (e.g. a connection timeout) propagates with the row
        // left pending: the outcome is unknown and Nets may have processed the
        // refund, so retrying with this same idempotency key reconciles the attempt
        // instead of issuing a second refund.

        $refundId = $response['refundId'] ?? null;

        $refund->forceFill([
            'nets_refund_id' => is_scalar($refundId) ? (string) $refundId : $refund->nets_refund_id,
        ])->save();

        return $refund;
    }

    /**
     * Get the amount that may still be refunded against this charge in minor units.
     *
     * Pending and completed refunds both reserve part of the balance. An
     * optional idempotency key is excluded from the calculation so retrying the
     * refund already recorded under that key reconciles instead of being capped.
     */
    public function remainingRefundable(?string $excludingIdempotencyKey = null): int
    {
        $refundModel = CashierNets::$refundModel;

        $query = $refundModel::query()
            ->where('nets_charge_id', $this->nets_charge_id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_COMPLETED]);

        if ($excludingIdempotencyKey !== null) {
            // A NULL idempotency_key (e.g. a refund recorded only via webhook)
            // must still count toward the reserved total: `idempotency_key != ?`
            // is UNKNOWN for NULL rows in SQL and would silently drop them, so
            // they are kept explicitly to avoid under-counting and over-refunding.
            $query->where(fn ($query) => $query
                ->where('idempotency_key', '!=', $excludingIdempotencyKey)
                ->orWhereNull('idempotency_key'));
        }

        $refunded = (int) $query->sum('amount');

        return max(0, $this->rawAmount() - $refunded);
    }

    /**
     * Ensure the transaction's charge may be refunded.
     */
    protected function ensureRefundable(): void
    {
        if (! $this->succeeded()) {
            throw new RuntimeException('Only succeeded transactions can be refunded.');
        }

        if (! $this->nets_charge_id) {
            throw new RuntimeException('The transaction does not have a Nets charge ID to refund.');
        }
    }

    /**
     * Record a pending refund attempt.
     */
    protected function recordPendingRefund(int $amount, string $idempotencyKey): Refund
    {
        $refundModel = CashierNets::$refundModel;

        return $refundModel::query()->updateOrCreate([
            'idempotency_key' => $idempotencyKey,
        ], [
            'billable_type' => $this->billable_type,
            'billable_id' => $this->billable_id,
            'nets_transaction_id' => $this->getKey(),
            'nets_charge_id' => $this->nets_charge_id,
            'nets_payment_id' => $this->nets_payment_id,
            'status' => Refund::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $this->currency,
        ]);
    }

    /**
     * Build a single zero-tax refund order item totalling the amount.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function refundOrderItems(int $amount): array
    {
        return [[
            'reference' => 'refund',
            'name' => 'Refund',
            'quantity' => 1,
            'unit' => 'pcs',
            'unitPrice' => $amount,
            'taxRate' => 0,
            'taxAmount' => 0,
            'grossTotalAmount' => $amount,
            'netTotalAmount' => $amount,
        ]];
    }

    /**
     * Generate the per-attempt refund idempotency key for this charge.
     */
    protected function refundIdempotencyKey(): string
    {
        $refundModel = CashierNets::$refundModel;

        $attempt = 1 + $refundModel::query()
            ->where('nets_charge_id', $this->nets_charge_id)
            ->count();

        return 'nets-refund-'.$this->nets_charge_id.'-a'.$attempt;
    }
}
