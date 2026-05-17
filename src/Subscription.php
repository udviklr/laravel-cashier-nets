<?php

namespace Udviklr\CashierNets;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use RuntimeException;

/**
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $status
 * @property string|null $nets_payment_id
 * @property string|null $nets_subscription_id
 * @property string|null $nets_unscheduled_subscription_id
 * @property int|null $amount
 * @property string|null $currency
 * @property int|null $interval_days
 * @property \Illuminate\Support\Carbon|null $next_charge_at
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property \Illuminate\Support\Carbon|null $paused_at
 */
class Subscription extends Model
{
    public const DEFAULT_TYPE = 'default';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';

    /**
     * The table associated with the model.
     */
    protected $table = 'nets_subscriptions';

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
        'interval_days' => 'integer',
        'metadata' => 'array',
        'next_charge_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'last_charged_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Get the billable model related to the subscription.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the transactions related to the subscription.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CashierNets::$transactionModel, 'nets_subscription_id', 'nets_subscription_id');
    }

    /**
     * Determine if the subscription is active, trialing, in grace, or allowed past due.
     */
    public function valid(): bool
    {
        return $this->onTrial()
            || $this->active()
            || $this->onGracePeriod()
            || (! CashierNets::$deactivatePastDue && $this->pastDue());
    }

    /**
     * Determine if the subscription is pending checkout completion.
     */
    public function pending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Determine if the subscription is active.
     */
    public function active(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Determine if the subscription is within its trial period.
     */
    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && ($this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }

    /**
     * Determine if the subscription trial has expired.
     */
    public function hasExpiredTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Determine if the subscription is past due.
     */
    public function pastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    /**
     * Determine if the subscription is paused.
     */
    public function paused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    /**
     * Determine if the subscription is canceled.
     */
    public function canceled(): bool
    {
        return $this->status === self::STATUS_CANCELED;
    }

    /**
     * Determine if the subscription is expired.
     */
    public function expired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    /**
     * Determine if the subscription is within its cancellation grace period.
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Determine if the subscription is within its pause grace period.
     */
    public function onPausedGracePeriod(): bool
    {
        return $this->paused_at && $this->paused_at->isFuture();
    }

    /**
     * Determine if the subscription should be charged.
     */
    public function dueForCharge(): bool
    {
        return $this->active()
            && $this->next_charge_at !== null
            && $this->next_charge_at->isPast();
    }

    /**
     * Get the formatted subscription amount.
     */
    public function amount(?string $locale = null): string
    {
        return CashierNets::formatAmount((int) $this->amount, (string) $this->currency, $locale);
    }

    /**
     * Charge the subscription through Nets.
     *
     * @param  array{amount?: int, currency?: string, description?: string, reference?: string, idempotency_key?: string, metadata?: array<string, mixed>}  $options
     */
    public function charge(array $options = []): Transaction
    {
        $this->ensureChargeable();

        $amount = $options['amount'] ?? $this->amount;
        $currency = $options['currency'] ?? $this->currency;

        if (! is_int($amount) || $amount < 0) {
            throw new InvalidArgumentException('A valid subscription charge amount is required.');
        }

        if (! is_string($currency) || $currency === '') {
            throw new InvalidArgumentException('A valid subscription charge currency is required.');
        }

        $idempotencyKey = $options['idempotency_key'] ?? $this->chargeIdempotencyKey();
        $transaction = $this->recordPendingCharge($amount, strtoupper($currency), $idempotencyKey, $options);

        try {
            $response = CashierNets::api(
                'POST',
                'v1/subscriptions/'.$this->nets_subscription_id.'/charges',
                $this->chargePayload($amount, strtoupper($currency), $options),
                ['idempotency_key' => $idempotencyKey],
            )->json();
        } catch (\Throwable $throwable) {
            $transaction->forceFill([
                'status' => Transaction::STATUS_FAILED,
                'failure_code' => $throwable instanceof \Udviklr\CashierNets\Exceptions\NetsException ? (string) $throwable->getCode() : null,
                'failure_message' => $throwable->getMessage(),
                'billed_at' => now(),
            ])->save();

            $this->forceFill([
                'status' => self::STATUS_PAST_DUE,
                'failed_at' => now(),
            ])->save();

            throw $throwable;
        }

        if (! is_array($response)) {
            return $transaction;
        }

        $transaction->forceFill([
            'nets_payment_id' => is_scalar($response['paymentId'] ?? null) ? (string) $response['paymentId'] : $transaction->nets_payment_id,
            'nets_charge_id' => is_scalar($response['chargeId'] ?? null) ? (string) $response['chargeId'] : $transaction->nets_charge_id,
        ])->save();

        return $transaction;
    }

    /**
     * Determine if the subscription can be retried after a failed charge.
     */
    public function chargeRetryable(): bool
    {
        $lastFailure = $this->chargeFailuresQuery()->latest('created_at')->first();

        if ($lastFailure instanceof Transaction && ! $lastFailure->retryable()) {
            return false;
        }

        $maxAttempts = (int) config('cashier-nets.retry_policy.max_attempts', 15);

        if ($maxAttempts < 1) {
            return false;
        }

        return $this->chargeFailuresQuery()->count() < $maxAttempts;
    }

    /**
     * Build the Nets subscription charge payload.
     *
     * @param  array{description?: string, reference?: string, metadata?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    public function chargePayload(int $amount, string $currency, array $options = []): array
    {
        $description = $options['description'] ?? ($this->metadata['description'] ?? 'Subscription renewal');
        $reference = $options['reference'] ?? ($this->metadata['reference'] ?? 'subscription-renewal');

        $payload = [
            'order' => [
                'items' => [[
                    'reference' => $reference,
                    'name' => $description,
                    'quantity' => 1,
                    'unit' => 'pcs',
                    'unitPrice' => $amount,
                    'taxRate' => 0,
                    'taxAmount' => 0,
                    'grossTotalAmount' => $amount,
                    'netTotalAmount' => $amount,
                ]],
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'reference' => $reference,
            ],
            'notifications' => [
                'webHooks' => CashierNets::webhooks(),
            ],
        ];

        if ($payload['notifications']['webHooks'] === []) {
            unset($payload['notifications']);
        }

        return $payload;
    }

    /**
     * Ensure the subscription may be charged.
     */
    protected function ensureChargeable(): void
    {
        if (! $this->nets_subscription_id) {
            throw new RuntimeException('The subscription does not have a Nets subscription ID.');
        }

        if ($this->canceled() || $this->expired() || $this->paused()) {
            throw new RuntimeException('The subscription cannot be charged in its current status.');
        }

        if ($this->pastDue() && ! $this->chargeRetryable()) {
            throw new RuntimeException('The subscription charge is not retryable.');
        }
    }

    /**
     * Record a pending charge attempt.
     *
     * @param  array{metadata?: array<string, mixed>}  $options
     */
    protected function recordPendingCharge(int $amount, string $currency, string $idempotencyKey, array $options): Transaction
    {
        $transactionModel = CashierNets::$transactionModel;

        return $transactionModel::query()->updateOrCreate([
            'idempotency_key' => $idempotencyKey,
        ], [
            'billable_type' => $this->billable_type,
            'billable_id' => $this->billable_id,
            'nets_subscription_id' => $this->nets_subscription_id,
            'nets_unscheduled_subscription_id' => $this->nets_unscheduled_subscription_id,
            'status' => Transaction::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => array_merge($options['metadata'] ?? [], [
                'source' => 'subscription_charge',
            ]),
        ]);
    }

    /**
     * Generate a stable idempotency key for the current due period.
     */
    protected function chargeIdempotencyKey(): string
    {
        $dueAt = $this->next_charge_at?->copy()->utc()->format('YmdHis') ?? now()->utc()->format('YmdHis');

        return 'nets-sub-'.$this->getKey().'-'.$dueAt;
    }

    /**
     * Query failed charge attempts in the configured retry window.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Udviklr\CashierNets\Transaction>
     */
    protected function chargeFailuresQuery(): Builder
    {
        $windowDays = (int) config('cashier-nets.retry_policy.window_days', 30);
        $transactionModel = CashierNets::$transactionModel;

        return $transactionModel::query()
            ->where('billable_type', $this->billable_type)
            ->where('billable_id', $this->billable_id)
            ->where('nets_subscription_id', $this->nets_subscription_id)
            ->where('status', Transaction::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays(max(1, $windowDays)));
    }

    /**
     * Retrieve the Nets payment object and sync provider identifiers locally.
     */
    public function syncFromNets(): self
    {
        if (! $this->nets_payment_id) {
            throw new RuntimeException('The subscription does not have a Nets payment ID.');
        }

        $payload = CashierNets::api('GET', 'v1/payments/'.$this->nets_payment_id)->json();

        if (! is_array($payload)) {
            return $this;
        }

        $updates = [];

        $subscriptionId = Arr::get($payload, 'payment.subscription.id')
            ?? Arr::get($payload, 'payment.subscription.subscriptionId')
            ?? Arr::get($payload, 'subscription.id')
            ?? Arr::get($payload, 'subscription.subscriptionId');

        if (is_string($subscriptionId) && $subscriptionId !== '') {
            $updates['nets_subscription_id'] = $subscriptionId;
            $updates['status'] = self::STATUS_ACTIVE;
        }

        $unscheduledSubscriptionId = Arr::get($payload, 'payment.unscheduledSubscription.id')
            ?? Arr::get($payload, 'payment.unscheduledSubscription.unscheduledSubscriptionId')
            ?? Arr::get($payload, 'unscheduledSubscription.id')
            ?? Arr::get($payload, 'unscheduledSubscription.unscheduledSubscriptionId');

        if (is_string($unscheduledSubscriptionId) && $unscheduledSubscriptionId !== '') {
            $updates['nets_unscheduled_subscription_id'] = $unscheduledSubscriptionId;
            $updates['status'] = self::STATUS_ACTIVE;
        }

        if ($updates !== []) {
            $this->forceFill($updates)->save();
        }

        return $this;
    }

    /**
     * Scope the query to valid subscriptions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('status', self::STATUS_ACTIVE)
                ->orWhere('status', self::STATUS_TRIALING)
                ->orWhere('ends_at', '>', Carbon::now());

            if (! CashierNets::$deactivatePastDue) {
                $query->orWhere('status', self::STATUS_PAST_DUE);
            }
        });
    }

    /**
     * Scope the query to subscriptions due for a charge.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeDueForCharge(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', Carbon::now());
    }

    /**
     * Get subscriptions that are due for a charge.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public function dueForChargeCollection(int $limit): EloquentCollection
    {
        return $this->newQuery()
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', Carbon::now())
            ->limit(max(1, $limit))
            ->get();
    }
}
