<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Workbench\App\Models\User;

class PastDueRetryTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://example.com']);
        URL::forceRootUrl('https://example.com');
        URL::forceScheme('https');
        Carbon::setTestNow(Carbon::parse('2026-05-17T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_first_retry_waits_for_the_first_backoff_interval(): void
    {
        $ready = $this->createPastDueSubscription(['type' => 'ready'], [
            Carbon::now()->subDay()->subHour(),
        ]);

        $this->createPastDueSubscription(['type' => 'too-soon'], [
            Carbon::now()->subHours(12),
        ]);

        $this->assertSame(
            [$ready->id],
            CashierNets::subscriptionModel()->dueForRetryCollection(10)->pluck('id')->all(),
        );
    }

    public function test_a_second_retry_waits_for_the_second_backoff_interval(): void
    {
        $ready = $this->createPastDueSubscription(['type' => 'ready'], [
            Carbon::now()->subDays(8),
            Carbon::now()->subDays(4),
        ]);

        $this->createPastDueSubscription(['type' => 'too-soon'], [
            Carbon::now()->subDays(6),
            Carbon::now()->subDays(2),
        ]);

        $this->assertSame(
            [$ready->id],
            CashierNets::subscriptionModel()->dueForRetryCollection(10)->pluck('id')->all(),
        );
    }

    public function test_retries_stop_once_the_backoff_schedule_is_exhausted(): void
    {
        // Third retry still waits backoff_days[2] = 5 days...
        $thirdRetry = $this->createPastDueSubscription(['type' => 'third'], [
            Carbon::now()->subDays(20),
            Carbon::now()->subDays(15),
            Carbon::now()->subDays(6),
        ]);

        // ...but after a fourth failure the schedule is exhausted for good.
        $this->createPastDueSubscription(['type' => 'exhausted'], [
            Carbon::now()->subDays(25),
            Carbon::now()->subDays(20),
            Carbon::now()->subDays(15),
            Carbon::now()->subDays(10),
        ]);

        $this->assertSame(
            [$thirdRetry->id],
            CashierNets::subscriptionModel()->dueForRetryCollection(10)->pluck('id')->all(),
        );
    }

    public function test_a_non_retryable_failure_code_blocks_automatic_retries(): void
    {
        $subscription = $this->createPastDueSubscription([], [
            Carbon::now()->subDays(2),
        ]);

        $subscription->transactions()
            ->where('status', Transaction::STATUS_FAILED)
            ->update(['failure_code' => '14']);

        $this->assertFalse($subscription->refresh()->dueForRetry());
        $this->assertTrue(CashierNets::subscriptionModel()->dueForRetryCollection(10)->isEmpty());
    }

    public function test_canceled_and_ended_past_due_subscriptions_are_never_retried(): void
    {
        $this->createPastDueSubscription([
            'type' => 'canceled',
            'status' => Subscription::STATUS_CANCELED,
        ], [
            Carbon::now()->subDays(2),
        ]);

        $this->createPastDueSubscription([
            'type' => 'ended',
            'ends_at' => Carbon::yesterday(),
        ], [
            Carbon::now()->subDays(2),
        ]);

        $this->assertTrue(CashierNets::subscriptionModel()->dueForRetryCollection(10)->isEmpty());
    }

    public function test_the_command_retries_eligible_past_due_subscriptions(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/subscriptions/sub_retry/charges' => Http::response([
                'paymentId' => 'pay_retry',
                'chargeId' => 'charge_retry',
            ]),
        ]);

        $subscription = $this->createPastDueSubscription([
            'nets_subscription_id' => 'sub_retry',
        ], [
            Carbon::now()->subDays(2),
        ]);

        $this->artisan('cashier-nets:retry-past-due')
            ->expectsOutput('Retried 1 past due subscription.')
            ->assertExitCode(0);

        Http::assertSentCount(1);

        $this->assertDatabaseHas('nets_transactions', [
            'nets_subscription_id' => 'sub_retry',
            'nets_charge_id' => 'charge_retry',
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    public function test_a_successful_retry_heals_the_subscription_through_the_webhook_flow(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/subscriptions/sub_retry/charges' => Http::response([
                'paymentId' => 'pay_retry',
                'chargeId' => 'charge_retry',
            ]),
        ]);

        $subscription = $this->createPastDueSubscription([
            'nets_subscription_id' => 'sub_retry',
        ], [
            Carbon::now()->subDays(2),
        ]);

        $this->artisan('cashier-nets:retry-past-due')->assertExitCode(0);

        $this->postJson('/nets/webhook', [
            'id' => 'evt_retry_charge_created',
            'event' => 'payment.charge.created.v2',
            'timestamp' => '2026-05-17T10:00:00.0000+00:00',
            'data' => [
                'paymentId' => 'pay_retry',
                'chargeId' => 'charge_retry',
                'subscriptionId' => 'sub_retry',
                'amount' => [
                    'amount' => '9900',
                    'currency' => 'DKK',
                ],
            ],
        ])->assertOk();

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->failed_at);
        $this->assertSame('2026-06-16 10:00:00', $subscription->next_charge_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    /**
     * Create a past-due subscription with a history of failed charge attempts.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, \Illuminate\Support\Carbon>  $failedAt
     */
    protected function createPastDueSubscription(array $attributes = [], array $failedAt = []): Subscription
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor'.User::query()->count().'@example.com',
            'password' => 'secret',
        ]);

        /** @var \Udviklr\CashierNets\Subscription $subscription */
        $subscription = $user->netsSubscriptions()->create(array_merge([
            'type' => Subscription::DEFAULT_TYPE,
            'nets_subscription_id' => 'sub_'.$user->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::now()->subDays(30),
            'failed_at' => $failedAt === [] ? Carbon::now()->subDay() : end($failedAt),
        ], $attributes));

        foreach (array_values($failedAt) as $index => $billedAt) {
            $subscription->transactions()->create([
                'billable_type' => $subscription->billable_type,
                'billable_id' => $subscription->billable_id,
                'nets_subscription_id' => $subscription->nets_subscription_id,
                'status' => Transaction::STATUS_FAILED,
                'failure_code' => null,
                'amount' => 9900,
                'currency' => 'DKK',
                'billed_at' => $billedAt,
                'created_at' => $billedAt,
                'updated_at' => $billedAt,
            ]);
        }

        return $subscription;
    }
}
