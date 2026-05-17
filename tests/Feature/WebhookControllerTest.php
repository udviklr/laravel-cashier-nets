<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\Events\WebhookHandled;
use Udviklr\CashierNets\Events\WebhookReceived;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
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

    public function test_it_records_and_processes_checkout_completed_webhooks(): void
    {
        Event::fake([
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
    }

    public function test_it_marks_the_subscription_past_due_when_payment_reservation_fails(): void
    {
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
     * Get a payment.charge.created.v2 webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function chargeCreatedPayload(): array
    {
        return [
            'id' => 'evt_charge_created',
            'event' => 'payment.charge.created.v2',
            'timestamp' => '2026-04-30T05:04:00.4502+00:00',
            'merchantId' => 0,
            'merchantNumber' => 100242833,
            'data' => [
                'paymentId' => 'pay_123',
                'chargeId' => 'charge_123',
                'subscriptionId' => 'sub_123',
                'reconciliationReference' => 'MRJhJvEDCx1y7uWlKfb6O3z78',
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
