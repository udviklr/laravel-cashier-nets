<?php

namespace Udviklr\CashierNets\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Udviklr\CashierNets\CashierNets;

class RetryPastDueSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cashier-nets:retry-past-due {--limit=100 : Maximum number of past due subscriptions to retry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry charging past due Nets subscriptions.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $failed = 0;
        $charged = 0;

        $subscriptions = CashierNets::subscriptionModel()->dueForRetryCollection($limit);

        foreach ($subscriptions as $subscription) {
            try {
                $subscription->charge();
                $charged++;
            } catch (Throwable $throwable) {
                $failed++;

                Log::error('Cashier Nets failed to retry a past due subscription charge.', [
                    'subscription_id' => $subscription->getKey(),
                    'nets_subscription_id' => $subscription->nets_subscription_id,
                    'exception' => $throwable,
                ]);

                $this->error(sprintf(
                    'Failed retrying subscription [%s]: %s',
                    (string) $subscription->getKey(),
                    $throwable->getMessage(),
                ));
            }
        }

        $this->info(sprintf('Retried %d past due subscription%s.', $charged, $charged === 1 ? '' : 's'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
