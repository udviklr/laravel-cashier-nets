<?php

namespace Udviklr\CashierNets\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Charges\ChargeReconciler;
use Udviklr\CashierNets\Transaction;

class ReconcileChargesCommand extends Command
{
    protected $signature = 'cashier-nets:reconcile-charges';

    protected $description = 'Resolve held and stale subscription charges using read-only provider requests.';

    public function handle(ChargeReconciler $reconciler): int
    {
        $failed = 0;
        $query = CashierNets::transactionModel()->newQuery();
        $query->where('status', Transaction::STATUS_PENDING)->whereNotNull('nets_subscription_id');
        $query->chunkById(100, function ($rows) use ($reconciler, &$failed): void {
            foreach ($rows as $row) {
                try {
                    if ($row->held() || $row->awaitingWebhookIsStale()) {
                        $reconciler->reconcile($row);
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    Log::error('Cashier Nets charge reconciliation failed.', ['transaction_id' => $row->getKey(), 'exception' => $exception]);
                    $this->error('Transaction ['.$row->getKey().']: '.$exception->getMessage());
                }
                try {
                    $reconciler->alertIfStalled($row);
                } catch (Throwable $exception) {
                    $failed++;
                    Log::error('Cashier Nets stalled-charge notification failed.', ['transaction_id' => $row->getKey(), 'exception' => $exception]);
                    $this->error('Transaction ['.$row->getKey().']: '.$exception->getMessage());
                }
            }
        });

        $this->info('Charge reconciliation finished.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
