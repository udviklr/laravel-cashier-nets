<?php

namespace Udviklr\CashierNets\Events\Concerns;

use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Refund;

trait ResolvesRefund
{
    /**
     * Resolve the local refund record for this event, if one exists.
     */
    public function refund(): ?Refund
    {
        $refundId = $this->payload->refundId();

        if ($refundId === null) {
            return null;
        }

        $refundModel = CashierNets::$refundModel;

        return $refundModel::query()->where('nets_refund_id', $refundId)->first();
    }
}
