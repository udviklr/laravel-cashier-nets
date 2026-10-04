<?php

namespace Udviklr\CashierNets\Charges;

use InvalidArgumentException;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

class ChargeRecorder
{
    /** Call under the subscription lock; reserved renewal attempts require retrieved-payment validation. */
    public function record(Subscription $subscription, ?string $paymentId, ?string $chargeId, ?int $amount, ?string $currency): Transaction
    {
        $query = CashierNets::transactionModel()->newQuery();
        $row = $chargeId !== null
            ? $query->where('nets_charge_id', $chargeId)->first()
            : $query->where('nets_payment_id', $paymentId)->first();
        if ($row !== null) {
            if (($row->nets_subscription_id !== null && $row->nets_subscription_id !== $subscription->nets_subscription_id)
                || ($row->nets_payment_id !== null && $paymentId !== null && $row->nets_payment_id !== $paymentId)
                || $row->billable_type !== $subscription->billable_type || (string) $row->billable_id !== (string) $subscription->billable_id) {
                throw new InvalidArgumentException('Charge transaction belongs to another payment or subscription.');
            }
            if ($row->frozen_order !== null) {
                throw new InvalidArgumentException('A reserved attempt requires retrieved payment validation.');
            }
            $row->nets_subscription_id = $row->nets_subscription_id ?? $subscription->nets_subscription_id;
            if ($amount !== null && ($row->status === Transaction::STATUS_PENDING || $row->amount === null)) {
                $row->amount = $amount;
            }
            if ($currency !== null && ($row->status === Transaction::STATUS_PENDING || $row->currency === null)) {
                $row->currency = $currency;
            }
            $row->save();

            return $row;
        }

        return CashierNets::transactionModel()->newQuery()->create([
            'billable_type' => $subscription->billable_type, 'billable_id' => $subscription->billable_id,
            'nets_payment_id' => $paymentId, 'nets_charge_id' => $chargeId,
            'nets_subscription_id' => $subscription->nets_subscription_id,
            'nets_unscheduled_subscription_id' => $subscription->nets_unscheduled_subscription_id,
            'status' => Transaction::STATUS_PENDING, 'amount' => $amount, 'currency' => $currency,
        ]);
    }
}
