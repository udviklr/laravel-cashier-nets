<?php

namespace Udviklr\CashierNets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string|null $nets_charge_id
 * @property string|null $nets_payment_id
 * @property string|null $nets_refund_id
 * @property int|string|null $nets_transaction_id
 * @property string|null $idempotency_key
 * @property int|null $amount
 * @property string|null $currency
 * @property string $status
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Refund extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * The table associated with the model.
     */
    protected $table = 'nets_refunds';

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
    ];

    /**
     * Get the billable model related to the refund.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the transaction (charge) the refund belongs to.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CashierNets::$transactionModel, 'nets_transaction_id');
    }

    /**
     * Get the raw refund amount in minor units.
     */
    public function rawAmount(): int
    {
        return (int) $this->amount;
    }

    /**
     * Get the formatted refund amount.
     */
    public function amount(?string $locale = null): string
    {
        return CashierNets::formatAmount($this->rawAmount(), (string) $this->currency, $locale);
    }

    /**
     * Determine if the refund is pending confirmation.
     */
    public function pending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Determine if the refund has completed.
     */
    public function completed(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Determine if the refund failed.
     */
    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
