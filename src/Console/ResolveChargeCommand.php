<?php

namespace Udviklr\CashierNets\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Charges\ChargeFinalizer;
use Udviklr\CashierNets\Charges\ChargeReconciler;
use Udviklr\CashierNets\Transaction;

class ResolveChargeCommand extends Command
{
    protected $signature = 'cashier-nets:resolve-charge {transaction : Local transaction ID}
        {--payment-id= : Payment found in the Nets portal}
        {--failed : Confirm after portal review that this attempt was not charged}
        {--reason= : Operator confirmation reason; required with --payment-id and --failed}';

    protected $description = 'Resolve a subscription charge by verified payment identity or operator confirmation.';

    public function handle(ChargeReconciler $reconciler, ChargeFinalizer $finalizer): int
    {
        try {
            $row = CashierNets::transactionModel()->newQuery()->findOrFail($this->argument('transaction'));
            $paymentId = $this->option('payment-id');
            $reason = $this->option('reason');
            if ($reason !== null && ! $this->option('failed')) {
                throw new InvalidArgumentException('--reason requires --failed.');
            }
            if ($paymentId !== null) {
                if (! is_string($paymentId) || trim($paymentId) === '') {
                    throw new InvalidArgumentException('A non-empty payment ID is required.');
                }
                $row = $reconciler->resolvePayment($row, $paymentId, (bool) $this->option('failed'), $reason);
            } elseif ($this->option('failed')) {
                $row = $finalizer->confirmFailed($row, $reason);
            } else {
                $row = $reconciler->reconcile($row, 'manual');
            }
            $row->refresh();
            $this->info($row->status === Transaction::STATUS_PENDING
                ? ($row->nets_payment_id === null ? 'No terminal outcome found; attempt remains unresolved.' : 'Payment identified; outcome remains unresolved.')
                : 'Transaction ['.$row->getKey().'] is '.$row->status.'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
