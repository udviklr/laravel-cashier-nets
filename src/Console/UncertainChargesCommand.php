<?php

namespace Udviklr\CashierNets\Console;

use Illuminate\Console\Command;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Transaction;

class UncertainChargesCommand extends Command
{
    protected $signature = 'cashier-nets:uncertain-charges';

    protected $description = 'List held and stale subscription charge attempts for operator review.';

    public function handle(): int
    {
        $model = CashierNets::transactionModel();
        $query = $model->newQuery();
        $model->scopeNeedsChargeReconciliation($query);
        $query->orderBy('created_at');
        $rows = $query->get();
        $this->table(['Transaction', 'Subscription', 'Amount', 'Key', 'Reference', 'Age', 'Reason'], $rows->map(fn (Transaction $row): array => [
            $row->getKey(), $row->nets_subscription_id,
            $row->amount !== null && $row->currency !== null ? $row->amount() : 'Unknown',
            $row->idempotency_key,
            $row->metadata['reference'] ?? '', $row->created_at?->diffForHumans(),
            $row->metadata['uncertain_reason'] ?? ($row->nets_charge_id === null ? 'No charge ID after grace period' : 'Awaiting webhook'),
        ])->all());

        return self::SUCCESS;
    }
}
