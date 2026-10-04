<?php

namespace Udviklr\CashierNets\Charges;

use InvalidArgumentException;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\CheckoutFinalizationException;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

class CheckoutChargeRecorder
{
    public function __construct(
        protected ChargeReconciler $reconciler,
        protected ChargeRecorder $recorder,
        protected ChargeFinalizer $finalizer,
    ) {}

    public function record(Subscription $subscription): Transaction
    {
        $paymentId = $subscription->nets_payment_id;
        if (! is_string($paymentId) || $paymentId === '') {
            throw new InvalidArgumentException('The subscription has no checkout payment ID.');
        }

        $payment = $this->reconciler->retrievePayment($paymentId);

        return $subscription->getConnection()->transaction(function () use ($subscription, $paymentId, $payment): Transaction {
            $query = $subscription->newQuery();
            $query->lockForUpdate();
            $locked = $query->findOrFail($subscription->getKey());
            if ($locked->nets_payment_id !== $paymentId
                || $locked->billable_type !== $subscription->billable_type
                || (string) $locked->billable_id !== (string) $subscription->billable_id
                || ! is_string($locked->nets_subscription_id) || $locked->nets_subscription_id === ''
                || $locked->amount === null || $locked->amount <= 0 || $locked->currency === null) {
                throw new InvalidArgumentException('Checkout recording requires the original payment, billable, mandate, amount and currency.');
            }

            $expected = CashierNets::transactionModel()->newInstance([
                'nets_payment_id' => $paymentId, 'nets_subscription_id' => $locked->nets_subscription_id,
                'amount' => $locked->amount, 'currency' => $locked->currency,
            ]);
            $outcome = $this->reconciler->outcomeFromPayment($expected, $payment, 'checkout');
            if ($outcome === null || $outcome->status !== Transaction::STATUS_SUCCEEDED || $outcome->providerOccurredAt === null) {
                throw new CheckoutFinalizationException('The checkout has no unambiguous full charge with a provider timestamp.');
            }

            $row = $this->recorder->record($locked, $paymentId, $outcome->chargeId, $locked->amount, $locked->currency);
            $this->reconciler->validateIdentity($row, $payment);

            return $this->finalizer->finalizeCharge($row, $outcome, updateSubscription: false);
        });
    }
}
