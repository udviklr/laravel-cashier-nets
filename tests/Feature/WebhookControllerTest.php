<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use RuntimeException;
use Tests\TestCase;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Events\CheckoutCompleted;
use Udviklr\CashierNets\Events\WebhookHandled;
use Udviklr\CashierNets\Events\WebhookReceived;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\WebhookEvent;
use Workbench\App\Models\User;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_it_rejects_webhooks_with_an_invalid_authorization_header(): void
    {
        config(['cashier-nets.webhook_authorization' => 'expected-secret']);

        $this->postJson('/nets/webhook', $this->checkoutCompletedPayload(), [
            'Authorization' => 'wrong-secret',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('nets_webhook_events', 0);
    }

    public function test_it_rejects_webhooks_in_production_when_no_secret_is_configured(): void
    {
        config(['cashier-nets.webhook_authorization' => null]);
        $this->useProductionEnvironment();

        Log::spy();

        $this->postJson('/nets/webhook', $this->checkoutCompletedPayload())
            ->assertStatus(503);

        $this->assertDatabaseCount('nets_webhook_events', 0);

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_it_rejects_webhooks_in_production_with_an_invalid_authorization_header(): void
    {
        config(['cashier-nets.webhook_authorization' => 'expected-secret']);
        $this->useProductionEnvironment();

        $this->postJson('/nets/webhook', $this->checkoutCompletedPayload(), [
            'Authorization' => 'wrong-secret',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('nets_webhook_events', 0);
    }

    public function test_the_authorization_required_flag_requires_a_secret_outside_production(): void
    {
        config([
            'cashier-nets.webhook_authorization' => null,
            'cashier-nets.webhook_authorization_required' => true,
        ]);

        $this->postJson('/nets/webhook', $this->checkoutCompletedPayload())
            ->assertStatus(503);

        $this->assertDatabaseCount('nets_webhook_events', 0);
    }

    public function test_the_authorization_required_flag_allows_a_missing_secret_in_production(): void
    {
        config([
            'cashier-nets.webhook_authorization' => null,
            'cashier-nets.webhook_authorization_required' => false,
        ]);
        $this->useProductionEnvironment();

        $this->postJson('/nets/webhook', $this->checkoutCompletedPayload())
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertDatabaseCount('nets_webhook_events', 1);
    }

    public function test_a_listener_exception_leaves_the_event_unprocessed_and_rolls_back_handler_writes(): void
    {
        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-04-30T05:04:00Z'),
        ]);

        Event::listen(ChargeSucceeded::class, function (): void {
            throw new RuntimeException('Consumer listener failed.');
        });

        $this->postJson('/nets/webhook', $this->chargeCreatedPayload())
            ->assertStatus(500);

        $this->assertDatabaseHas('nets_webhook_events', [
            'nets_event_id' => 'evt_charge_created',
            'processed_at' => null,
        ]);

        $this->assertDatabaseCount('nets_transactions', 0);

        $subscription->refresh();

        $this->assertSame('2026-04-30 05:04:00', $subscription->next_charge_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertNull($subscription->last_charged_at);
    }

    public function test_redelivery_after_a_failed_listener_reprocesses_the_event(): void
    {
        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-04-30T05:04:00Z'),
        ]);

        $shouldThrow = true;

        Event::listen(ChargeSucceeded::class, function () use (&$shouldThrow): void {
            if ($shouldThrow) {
                throw new RuntimeException('Consumer listener failed.');
            }
        });

        $payload = $this->chargeCreatedPayload();

        $this->postJson('/nets/webhook', $payload)->assertStatus(500);

        $shouldThrow = false;

        $this->postJson('/nets/webhook', $payload)
            ->assertOk()
            ->assertJson(['received' => true])
            ->assertJsonMissing(['duplicate' => true]);

        $this->assertDatabaseCount('nets_webhook_events', 1);
        $this->assertDatabaseCount('nets_transactions', 1);

        $event = WebhookEvent::query()->where('nets_event_id', 'evt_charge_created')->first();

        $this->assertNotNull($event?->processed_at);

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('2026-05-30 05:04:00', $subscription->next_charge_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_it_processes_webhooks_without_an_event_id(): void
    {
        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'status' => Subscription::STATUS_PENDING,
        ]);

        $payload = $this->checkoutCompletedPayload();
        unset($payload['id']);

        $this->postJson('/nets/webhook', $payload)
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->refresh()->status);

        $this->assertDatabaseHas('nets_webhook_events', [
            'nets_event_id' => null,
            'event_name' => 'payment.checkout.completed',
        ]);
    }

    public function test_it_records_and_processes_checkout_completed_webhooks(): void
    {
        Event::fake([
            CheckoutCompleted::class,
            WebhookHandled::class,
            WebhookReceived::class,
        ]);

        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'status' => Subscription::STATUS_PENDING,
        ]);

        $this->postJson('/nets/webhook', $this->checkoutCompletedPayload())
            ->assertOk()
            ->assertJson(['received' => true]);

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('sub_123', $subscription->nets_subscription_id);
        $this->assertSame(9900, $subscription->amount);
        $this->assertSame('DKK', $subscription->currency);

        $this->assertDatabaseHas('nets_webhook_events', [
            'nets_event_id' => 'evt_checkout_completed',
            'event_name' => 'payment.checkout.completed',
        ]);

        $this->assertNotNull($subscription->fresh());

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(WebhookHandled::class);
        Event::assertDispatched(CheckoutCompleted::class, function (CheckoutCompleted $event) use ($subscription): bool {
            return $event->payload->paymentId() === 'pay_123'
                && $event->webhookEvent->event_name === 'payment.checkout.completed'
                && $event->subscription?->is($subscription);
        });
    }

    public function test_checkout_completed_without_a_subscription_id_keeps_the_subscription_pending(): void
    {
        Event::fake([
            CheckoutCompleted::class,
        ]);

        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'status' => Subscription::STATUS_PENDING,
            'amount' => 5000,
            'currency' => 'DKK',
        ]);

        $payload = $this->checkoutCompletedPayload();
        unset($payload['data']['subscriptionId']);

        $this->postJson('/nets/webhook', $payload)
            ->assertOk()
            ->assertJson(['received' => true]);

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PENDING, $subscription->status);
        $this->assertNull($subscription->nets_subscription_id);
        $this->assertSame(9900, $subscription->amount);
        $this->assertSame('DKK', $subscription->currency);

        Event::assertDispatched(CheckoutCompleted::class, function (CheckoutCompleted $event) use ($subscription): bool {
            return $event->payload->paymentId() === 'pay_123'
                && $event->subscription?->is($subscription)
                && $event->subscription?->status === Subscription::STATUS_PENDING;
        });
    }

    public function test_it_records_subscription_identifiers_from_payment_created_webhooks(): void
    {
        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'status' => Subscription::STATUS_PENDING,
        ]);

        $this->postJson('/nets/webhook', $this->paymentCreatedPayload())
            ->assertOk()
            ->assertJson(['received' => true]);

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PENDING, $subscription->status);
        $this->assertSame('sub_123', $subscription->nets_subscription_id);

        $this->assertDatabaseHas('nets_webhook_events', [
            'nets_event_id' => 'evt_payment_created',
            'event_name' => 'payment.created',
        ]);
    }

    public function test_it_handles_duplicate_webhooks_idempotently(): void
    {
        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-04-30T05:04:00Z'),
        ]);

        $payload = $this->chargeCreatedPayload();

        $this->postJson('/nets/webhook', $payload)->assertOk();

        $this->postJson('/nets/webhook', $payload)
            ->assertOk()
            ->assertJson([
                'received' => true,
                'duplicate' => true,
            ]);

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('2026-05-30 05:04:00', $subscription->next_charge_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-30 05:04:00', $subscription->last_charged_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));

        $this->assertDatabaseCount('nets_webhook_events', 1);
        $this->assertDatabaseCount('nets_transactions', 1);
        $this->assertDatabaseHas('nets_transactions', [
            'nets_payment_id' => 'pay_123',
            'nets_charge_id' => 'charge_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_SUCCEEDED,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $transaction = Transaction::query()->first();

        $this->assertNotNull($transaction);
        $this->assertSame('INV-2026-000124', $transaction->metadata['my_reference']);
        $this->assertSame('NEXI-2026-000124', $transaction->metadata['invoice_number']);
    }

    public function test_it_handles_legacy_charge_created_webhooks(): void
    {
        Event::fake([
            ChargeSucceeded::class,
        ]);

        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_PAST_DUE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::parse('2026-04-30T05:04:00Z'),
            'failed_at' => Carbon::parse('2026-04-29T05:04:00Z'),
        ]);

        $this->postJson('/nets/webhook', $this->chargeCreatedPayload('payment.charge.created', 'evt_charge_created_legacy'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->failed_at);
        $this->assertSame('2026-05-30 05:04:00', $subscription->next_charge_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));

        $this->assertDatabaseHas('nets_transactions', [
            'nets_payment_id' => 'pay_123',
            'nets_charge_id' => 'charge_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_SUCCEEDED,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        Event::assertDispatched(ChargeSucceeded::class, function (ChargeSucceeded $event) use ($subscription): bool {
            return $event->payload->eventName() === 'payment.charge.created'
                && $event->subscription?->is($subscription)
                && $event->transaction?->status === Transaction::STATUS_SUCCEEDED;
        });
    }

    public function test_it_marks_the_subscription_past_due_when_payment_reservation_fails(): void
    {
        Event::fake([
            ChargeFailed::class,
        ]);

        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $this->postJson('/nets/webhook', $this->reservationFailedPayload())->assertOk();

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertSame('2026-04-30 05:04:00', $subscription->failed_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));

        $this->assertDatabaseHas('nets_transactions', [
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_FAILED,
            'amount' => 9900,
            'currency' => 'DKK',
            'failure_code' => 'DECLINED',
            'failure_message' => 'Card was declined.',
        ]);

        Event::assertDispatched(ChargeFailed::class, function (ChargeFailed $event) use ($subscription): bool {
            return $event->payload->eventName() === 'payment.reservation.failed'
                && $event->subscription?->is($subscription)
                && $event->transaction?->status === Transaction::STATUS_FAILED;
        });
    }

    public function test_it_marks_the_subscription_past_due_when_charge_failed_v2_webhooks_are_received(): void
    {
        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $this->postJson('/nets/webhook', $this->chargeFailedPayload())->assertOk();

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertSame('2026-04-30 05:04:00', $subscription->failed_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));

        $this->assertDatabaseHas('nets_transactions', [
            'nets_payment_id' => 'pay_123',
            'nets_charge_id' => 'charge_failed_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_FAILED,
            'amount' => 9900,
            'currency' => 'DKK',
            'failure_code' => 'DECLINED',
            'failure_message' => 'Charge was declined.',
        ]);
    }

    public function test_it_marks_the_subscription_past_due_when_legacy_charge_failed_webhooks_are_received(): void
    {
        $subscription = $this->createSubscription([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $this->postJson('/nets/webhook', $this->chargeFailedPayload('payment.charge.failed', 'evt_charge_failed_legacy'))->assertOk();

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertDatabaseHas('nets_transactions', [
            'nets_payment_id' => 'pay_123',
            'nets_charge_id' => 'charge_failed_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_FAILED,
            'failure_code' => 'DECLINED',
        ]);
    }

    /**
     * Run the test in a production application environment.
     *
     * The environment is restored before teardown so testbench's own console
     * commands do not trip Laravel's production confirmation prompt.
     */
    protected function useProductionEnvironment(): void
    {
        $this->withoutMiddleware();

        $this->app['env'] = 'production';

        $this->beforeApplicationDestroyed(function (): void {
            $this->app['env'] = 'testing';
        });
    }

    /**
     * Create a local subscription record for webhook tests.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createSubscription(array $attributes): Subscription
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        return $user->netsSubscriptions()->create($attributes);
    }

    /**
     * Get a payment.checkout.completed webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function checkoutCompletedPayload(): array
    {
        return [
            'id' => 'evt_checkout_completed',
            'event' => 'payment.checkout.completed',
            'timestamp' => '2026-04-30T05:04:00.4451+00:00',
            'merchantId' => 100242833,
            'merchantNumber' => 0,
            'data' => [
                'paymentId' => 'pay_123',
                'subscriptionId' => 'sub_123',
                'order' => [
                    'amount' => [
                        'amount' => '9900',
                        'currency' => 'DKK',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get a payment.created webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function paymentCreatedPayload(): array
    {
        return [
            'id' => 'evt_payment_created',
            'event' => 'payment.created',
            'timestamp' => '2026-04-30T05:04:00.4451+00:00',
            'merchantId' => 100242833,
            'merchantNumber' => 0,
            'data' => [
                'paymentId' => 'pay_123',
                'subscriptionId' => 'sub_123',
            ],
        ];
    }

    /**
     * Get a payment.charge.created.v2 webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function chargeCreatedPayload(string $event = 'payment.charge.created.v2', string $id = 'evt_charge_created'): array
    {
        return [
            'id' => $id,
            'event' => $event,
            'timestamp' => '2026-04-30T05:04:00.4502+00:00',
            'merchantId' => 0,
            'merchantNumber' => 100242833,
            'data' => [
                'paymentId' => 'pay_123',
                'chargeId' => 'charge_123',
                'subscriptionId' => 'sub_123',
                'reconciliationReference' => 'MRJhJvEDCx1y7uWlKfb6O3z78',
                'myReference' => 'INV-2026-000124',
                'invoiceNumber' => 'NEXI-2026-000124',
                'amount' => [
                    'amount' => '9900',
                    'currency' => 'DKK',
                ],
            ],
        ];
    }

    /**
     * Get a payment.charge.failed.v2 webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function chargeFailedPayload(string $event = 'payment.charge.failed.v2', string $id = 'evt_charge_failed'): array
    {
        return [
            'id' => $id,
            'event' => $event,
            'timestamp' => '2026-04-30T05:04:00.4503+00:00',
            'merchantId' => 0,
            'merchantNumber' => 100242833,
            'data' => [
                'paymentId' => 'pay_123',
                'chargeId' => 'charge_failed_123',
                'subscriptionId' => 'sub_123',
                'error' => [
                    'code' => 'DECLINED',
                    'message' => 'Charge was declined.',
                ],
                'amount' => [
                    'amount' => '9900',
                    'currency' => 'DKK',
                ],
            ],
        ];
    }

    /**
     * Get a payment.reservation.failed webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function reservationFailedPayload(): array
    {
        return [
            'id' => 'evt_reservation_failed',
            'event' => 'payment.reservation.failed',
            'timestamp' => '2026-04-30T05:04:00.4503+00:00',
            'merchantId' => 0,
            'merchantNumber' => 100242833,
            'data' => [
                'paymentId' => 'pay_123',
                'subscriptionId' => 'sub_123',
                'error' => [
                    'code' => 'DECLINED',
                    'message' => 'Card was declined.',
                ],
                'amount' => [
                    'amount' => '9900',
                    'currency' => 'DKK',
                ],
            ],
        ];
    }
}
