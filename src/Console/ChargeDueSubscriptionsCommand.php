<?php

namespace Udviklr\CashierNets\Console;

use Illuminate\Console\Command;
use Throwable;
use Udviklr\CashierNets\CashierNets;

class ChargeDueSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cashier-nets:charge-due {--limit=100 : Maximum number of due subscriptions to charge}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge due Nets subscriptions.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $failed = 0;
        $charged = 0;

        $subscriptions = CashierNets::subscriptionModel()->dueForChargeCollection($limit);

        foreach ($subscriptions as $subscription) {
            try {
                $subscription->charge();
                $charged++;
            } catch (Throwable $throwable) {
                $failed++;
                $this->error(sprintf(
                    'Failed charging subscription [%s]: %s',
                    (string) $subscription->getKey(),
                    $throwable->getMessage(),
                ));
            }
        }

        $this->info(sprintf('Charged %d due subscription%s.', $charged, $charged === 1 ? '' : 's'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
