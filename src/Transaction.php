<?php

namespace Udviklr\CashierNets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string|null $nets_payment_id
 * @property string|null $nets_charge_id
 * @property string|null $idempotency_key
 * @property int|null $amount
 * @property string|null $currency
 * @property string $status
 * @property string|null $failure_code
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
            'billed_at' => 'datetime',
        ];
    }

    /**
     * Get the billable model related to the transaction.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
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
}
