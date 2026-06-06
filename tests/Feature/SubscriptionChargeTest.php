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
                'invoice' => [
                    'invoiceNumber' => 'NEXI-2026-000124',
                ],
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
            'my_reference' => 'INV-2026-000124',
            'idempotency_key' => 'idem_123',
        ]);

        $this->assertSame(Transaction::STATUS_PENDING, $transaction->status);
        $this->assertSame('pay_renewal_123', $transaction->fresh()->nets_payment_id);
        $this->assertSame('charge_123', $transaction->fresh()->nets_charge_id);
        $this->assertSame('idem_123', $transaction->fresh()->idempotency_key);
        $this->assertSame('INV-2026-000124', $transaction->fresh()->metadata['my_reference']);
        $this->assertSame('NEXI-2026-000124', $transaction->fresh()->metadata['invoice_number']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === 'https://test.api.dibspayment.eu/v1/subscriptions/sub_123/charges'
                && $request->header('Idempotency-Key') === ['idem_123']
                && $payload['order']['amount'] === 9900
                && $payload['order']['currency'] === 'DKK'
                && $payload['order']['items'][0]['name'] === 'Pro plan renewal'
                && $payload['order']['items'][0]['reference'] === 'pro-plan'
                && $payload['myReference'] === 'INV-2026-000124'
                && $payload['notifications']['webHooks'][0]['url'] === 'https://example.com/nets/webhook';
        });

        Http::assertSentCount(1);
    }

    public function test_a_subscription_charge_can_use_custom_order_items(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/subscriptions/sub_123/charges' => Http::response([
                'paymentId' => 'pay_taxed',
                'chargeId' => 'charge_taxed',
            ]),
        ]);

        // unitPrice excludes VAT, so the persisted snapshot stores the net unit price (4000).
        $subscription = $this->createSubscription([
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 5000,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-05-17T09:00:00Z'),
            'metadata' => [
                'order_items' => [[
                    'reference' => 'business-yearly',
                    'name' => 'Business - Yearly',
                    'quantity' => 1,
                    'unit' => 'pcs',
                    'unitPrice' => 4000,
                    'taxRate' => 2500,
                    'taxAmount' => 1000,
                    'grossTotalAmount' => 5000,
                    'netTotalAmount' => 4000,
                ]],
            ],
        ]);

        $subscription->charge(['idempotency_key' => 'idem_taxed']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);
            $item = $payload['order']['items'][0];

            return $request->url() === 'https://test.api.dibspayment.eu/v1/subscriptions/sub_123/charges'
                && $item['unitPrice'] === 4000
                && $item['taxRate'] === 2500
                && $item['taxAmount'] === 1000
                && $item['netTotalAmount'] === 4000
                && $item['grossTotalAmount'] === 5000
                && $item['netTotalAmount'] === $item['unitPrice'] * $item['quantity']
                && $item['grossTotalAmount'] === $item['netTotalAmount'] + $item['taxAmount']
                && $payload['order']['amount'] === $item['grossTotalAmount'];
        });
    }

    public function test_a_subscription_charge_rejects_inconsistent_order_items(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/subscriptions/sub_123/charges' => Http::response([
                'paymentId' => 'pay_taxed',
                'chargeId' => 'charge_taxed',
            ]),
        ]);

        // A poisoned snapshot: unitPrice is the gross amount, so netTotalAmount (4000) !=
        // unitPrice * quantity (5000). Without local validation this would only surface as a
        // failed Nets renewal that silently flips the subscription to past due.
        $subscription = $this->createSubscription([
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 5000,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-05-17T09:00:00Z'),
            'metadata' => [
                'order_items' => [[
                    'reference' => 'business-yearly',
                    'name' => 'Business - Yearly',
                    'quantity' => 1,
                    'unit' => 'pcs',
                    'unitPrice' => 5000,
                    'taxRate' => 2500,
                    'taxAmount' => 1000,
                    'grossTotalAmount' => 5000,
                    'netTotalAmount' => 4000,
                ]],
            ],
        ]);

        try {
            $subscription->charge(['idempotency_key' => 'idem_taxed']);
            $this->fail('Expected an InvalidArgumentException for inconsistent order items.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unitPrice', $e->getMessage());
        }

        // The charge fails before any API call, pending transaction, or past-due transition.
        Http::assertNothingSent();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame(0, $subscription->transactions()->count());
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

    public function test_a_subscription_charge_rejects_too_long_my_reference_values_before_sending_the_request(): void
    {
        $subscription = $this->createSubscription([
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'next_charge_at' => Carbon::parse('2026-05-17T09:00:00Z'),
        ]);

        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The Nets myReference value may not be greater than 36 characters.');

        try {
            $subscription->charge([
                'my_reference' => str_repeat('A', 37),
            ]);
        } finally {
            Http::assertNothingSent();
        }
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
