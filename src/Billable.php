<?php

namespace Udviklr\CashierNets;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait Billable
{
    /**
     * Get the Nets customer record for the model.
     */
    public function netsCustomer(): MorphOne
    {
        return $this->morphOne(CashierNets::$customerModel, 'billable');
    }

    /**
     * Create or update the Nets customer record for the model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createAsNetsCustomer(array $attributes = []): Customer
    {
        $defaults = [
            'email' => $this->getAttribute('email'),
            'name' => $this->getAttribute('name'),
        ];

        /** @var \Udviklr\CashierNets\Customer $customer */
        $customer = $this->netsCustomer()->updateOrCreate([], array_merge($defaults, $attributes));

        return $customer;
    }

    /**
     * Start building a new Nets subscription checkout.
     */
    public function newNetsSubscription(string $type = Subscription::DEFAULT_TYPE): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $type);
    }

    /**
     * Get all Nets subscriptions for the model.
     */
    public function netsSubscriptions(): MorphMany
    {
        return $this->morphMany(CashierNets::$subscriptionModel, 'billable');
    }

    /**
     * Get a Nets subscription by type.
     */
    public function netsSubscription(string $type = Subscription::DEFAULT_TYPE): ?Subscription
    {
        /** @var \Udviklr\CashierNets\Subscription|null $subscription */
        $subscription = $this->netsSubscriptions()->where('type', $type)->first();

        return $subscription;
    }

    /**
     * Determine if the model has a valid Nets subscription.
     */
    public function subscribed(string $type = Subscription::DEFAULT_TYPE): bool
    {
        return (bool) $this->netsSubscription($type)?->valid();
    }

    /**
     * Determine if the model is on a trial.
     */
    public function onTrial(?string $type = null): bool
    {
        if ($type === null) {
            return $this->onGenericTrial() || $this->netsSubscriptions()->get()->contains(function ($subscription): bool {
                return $subscription instanceof Subscription && $subscription->onTrial();
            });
        }

        return (bool) $this->netsSubscription($type)?->onTrial();
    }

    /**
     * Determine if the model is on a generic trial.
     */
    public function onGenericTrial(): bool
    {
        $customer = $this->netsCustomer()->first();

        return $customer instanceof Customer && $customer->onGenericTrial();
    }

    /**
     * Get the trial end date for the model.
     */
    public function trialEndsAt(?string $type = null): mixed
    {
        if ($type === null) {
            $customer = $this->netsCustomer()->first();

            return $customer instanceof Customer ? $customer->trial_ends_at : null;
        }

        return $this->netsSubscription($type)?->trial_ends_at;
    }

    /**
     * Get all Nets transactions for the model.
     */
    public function netsTransactions(): MorphMany
    {
        return $this->morphMany(CashierNets::$transactionModel, 'billable');
    }
}
