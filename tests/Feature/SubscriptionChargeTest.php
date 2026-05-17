<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use RuntimeException;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\NetsException;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Workbench\App\Models\User;

class SubscriptionChargeTest extends TestCase
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

    public function test_a_subscription_can_be_charged(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/subscriptions/sub_123/charges' => Http::response([
                'paymentId' => 'pay_renewal_123',
                'chargeId' => 'charge_123',
            ]),
        ]);

        $subscription = $this->createSubscription([
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-05-17T09:00:00Z'),
        ]);

        $transaction = $subscription->charge([
            'description' => 'Pro plan renewal',
            'reference' => 'pro-plan',
            'idempotency_key' => 'idem_123',
        ]);

        $this->assertSame(Transaction::STATUS_PENDING, $transaction->status);
        $this->assertSame('pay_renewal_123', $transaction->fresh()->nets_payment_id);
        $this->assertSame('charge_123', $transaction->fresh()->nets_charge_id);
        $this->assertSame('idem_123', $transaction->fresh()->idempotency_key);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === 'https://test.api.dibspayment.eu/v1/subscriptions/sub_123/charges'
                && $request->header('Idempotency-Key') === ['idem_123']
                && $payload['order']['amount'] === 9900
                && $payload['order']['currency'] === 'DKK'
                && $payload['order']['items'][0]['name'] === 'Pro plan renewal'
                && $payload['order']['items'][0]['reference'] === 'pro-plan'
                && $payload['notifications']['webHooks'][0]['url'] === 'https://example.com/nets/webhook';
        });
    }

    public function test_a_failed_api_charge_marks_the_subscription_past_due(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/subscriptions/sub_123/charges' => Http::response([
                'message' => 'Charge was rejected.',
            ], 402),
        ]);

        $subscription = $this->createSubscription([
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'next_charge_at' => Carbon::parse('2026-05-17T09:00:00Z'),
        ]);

        try {
            $subscription->charge(['idempotency_key' => 'idem_failed']);
            $this->fail('The charge should have thrown a Nets exception.');
        } catch (NetsException $exception) {
            $this->assertSame('Charge was rejected.', $exception->getMessage());
        }

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertNotNull($subscription->failed_at);

        $this->assertDatabaseHas('nets_transactions', [
            'idempotency_key' => 'idem_failed',
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_FAILED,
            'failure_code' => '402',
            'failure_message' => 'Charge was rejected.',
        ]);
    }

    public function test_a_non_retryable_failure_code_blocks_manual_retry(): void
    {
        $subscription = $this->createSubscription([
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_PAST_DUE,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $subscription->transactions()->create([
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_FAILED,
            'failure_code' => '14',
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The subscription charge is not retryable.');

        try {
            $subscription->charge();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_command_charges_due_subscriptions(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/subscriptions/sub_due/charges' => Http::response([
                'paymentId' => 'pay_due',
                'chargeId' => 'charge_due',
            ]),
        ]);

        $due = $this->createSubscription([
            'type' => 'due',
            'nets_subscription_id' => 'sub_due',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-05-17T09:00:00Z'),
        ]);

        $this->createSubscription([
            'type' => 'later',
            'nets_subscription_id' => 'sub_later',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-05-18T09:00:00Z'),
        ]);

        $this->artisan('cashier-nets:charge-due')
            ->expectsOutput('Charged 1 due subscription.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('nets_transactions', [
            'idempotency_key' => 'nets-sub-'.$due->id.'-20260517090000',
            'nets_subscription_id' => 'sub_due',
            'nets_payment_id' => 'pay_due',
            'nets_charge_id' => 'charge_due',
            'status' => Transaction::STATUS_PENDING,
        ]);

        Http::assertSentCount(1);
    }

    /**
     * Create a local subscription record.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createSubscription(array $attributes): Subscription
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor'.User::query()->count().'@example.com',
            'password' => 'secret',
        ]);

        return $user->netsSubscriptions()->create(array_merge([
            'type' => Subscription::DEFAULT_TYPE,
        ], $attributes));
    }
}
