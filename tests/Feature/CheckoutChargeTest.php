<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Support\ChargeTestCase;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Exceptions\CheckoutFinalizationException;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;

class CheckoutChargeTest extends ChargeTestCase
{
    public function test_callback_activation_can_record_and_refund_the_checkout_without_a_charge_webhook(): void
    {
        $subscription = $this->checkout(['status' => Subscription::STATUS_PENDING]);
        $this->fakeCheckout($subscription);
        Http::fake(['*/v1/charges/'.self::CHARGE_ID.'/refunds' => Http::response(['refundId' => 'checkout-refund'])]);
        $subscription = $subscription->billable->syncNetsSubscriptionFromPayment(self::PAYMENT_ID);
        $nextCharge = $subscription->next_charge_at;

        $transaction = $subscription->recordCheckoutCharge();
        $replayed = $subscription->recordCheckoutCharge();
        $refund = $transaction->refund();

        $this->assertTrue($transaction->is($replayed));
        $this->assertSame(Transaction::STATUS_SUCCEEDED, $transaction->status);
        $this->assertSame(self::CHARGE_ID, $transaction->nets_charge_id);
        $this->assertTrue($transaction->billed_at->equalTo(now()->subDays(2)));
        $this->assertTrue($subscription->fresh()->next_charge_at->equalTo($nextCharge));
        $this->assertSame('checkout-refund', $refund->nets_refund_id);
        $this->assertSame(1000, $refund->amount);
        $this->assertDatabaseCount('nets_transactions', 1);
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
        $this->assertDatabaseHas('nets_webhook_events', ['source' => 'checkout', 'event_name' => 'payment.charge.created.v2']);
        Http::assertSentCount(4);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_polling_and_webhooks_converge_and_preserve_a_completed_refund(bool $webhookFirst): void
    {
        $subscription = $this->checkout();
        $this->fakeCheckout($subscription);
        $payload = $this->webhook(new Transaction(['amount' => 1000, 'currency' => 'DKK', 'nets_subscription_id' => $subscription->nets_subscription_id]), true, [
            'timestamp' => now()->subDays(2)->toIso8601String(),
        ]);
        if ($webhookFirst) {
            $this->deliver($payload)->assertOk();
        }

        $transaction = $subscription->recordCheckoutCharge();
        $this->deliver($payload)->assertOk();
        $this->deliver([
            'id' => 'refund-completed', 'event' => 'payment.refund.completed',
            'data' => ['paymentId' => self::PAYMENT_ID, 'chargeId' => self::CHARGE_ID, 'refundId' => 'refund', 'amount' => ['amount' => 1000, 'currency' => 'DKK']],
        ])->assertOk();
        $recovered = $subscription->recordCheckoutCharge();

        $this->assertTrue($transaction->is($recovered));
        $this->assertSame(Transaction::STATUS_REFUNDED, $recovered->status);
        $this->assertTrue($recovered->billed_at->equalTo(now()->subDays(2)));
        $this->assertDatabaseCount('nets_transactions', 1);
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
    }

    #[TestWith(['canceled'])]
    #[TestWith(['expired'])]
    #[TestWith(['paused'])]
    public function test_recovering_a_charge_does_not_rearm_an_ended_or_paused_subscription(string $status): void
    {
        $subscription = $this->checkout(['status' => $status, 'next_charge_at' => null, 'ends_at' => now()->subDay()]);
        $this->fakeCheckout($subscription);

        $transaction = $subscription->recordCheckoutCharge();
        $this->deliver($this->webhook($transaction))->assertOk();

        $this->assertSame(Transaction::STATUS_SUCCEEDED, $transaction->fresh()->status);
        $this->assertSame($status, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertFalse($subscription->fresh()->dueForCharge());
    }

    #[TestWith(['paymentId', 'other-payment'])]
    #[TestWith(['subscription.id', 'other-subscription'])]
    #[TestWith(['orderDetails.amount', 2000])]
    #[TestWith(['orderDetails.currency', 'EUR'])]
    public function test_mismatched_provider_facts_cannot_create_a_transaction(string $key, mixed $value): void
    {
        $subscription = $this->checkout();
        $this->fakeCheckout($subscription, $key, $value);
        $this->expectException(InvalidArgumentException::class);

        try {
            $subscription->recordCheckoutCharge();
        } finally {
            $this->assertDatabaseCount('nets_transactions', 0);
            Event::assertNotDispatched(ChargeSucceeded::class);
        }
    }

    #[TestWith(['charges', []])]
    #[TestWith(['charges.0.created', null])]
    #[TestWith(['charges.0.created', 'invalid'])]
    #[TestWith(['charges.0.amount', 500])]
    #[TestWith(['summary.chargedAmount', 0])]
    public function test_unsettled_or_incomplete_payments_remain_unrecorded(string $key, mixed $value): void
    {
        $subscription = $this->checkout();
        $this->fakeCheckout($subscription, $key, $value);
        $this->expectException(CheckoutFinalizationException::class);

        try {
            $subscription->recordCheckoutCharge();
        } finally {
            $this->assertDatabaseCount('nets_transactions', 0);
            Event::assertNotDispatched(ChargeSucceeded::class);
        }
    }

    public function test_a_listener_failure_rolls_back_the_transaction_and_can_be_retried(): void
    {
        $subscription = $this->checkout();
        $this->fakeCheckout($subscription);
        Event::getFacadeRoot()->except([ChargeSucceeded::class]);
        $shouldFail = true;
        Event::listen(ChargeSucceeded::class, function () use (&$shouldFail): void {
            if ($shouldFail) {
                throw new RuntimeException('Application settlement failed');
            }
        });

        try {
            $subscription->recordCheckoutCharge();
            $this->fail('Expected the listener failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Application settlement failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('nets_transactions', 0);
        $this->assertDatabaseCount('nets_webhook_events', 0);
        $shouldFail = false;

        $this->assertSame(Transaction::STATUS_SUCCEEDED, $subscription->recordCheckoutCharge()->status);
    }

    public function test_a_charge_already_owned_by_another_billable_is_rejected(): void
    {
        $other = $this->checkout(['nets_payment_id' => 'other-checkout']);
        $other->billable->netsTransactions()->create([
            'nets_subscription_id' => $other->nets_subscription_id, 'nets_payment_id' => 'other-checkout',
            'nets_charge_id' => self::CHARGE_ID, 'status' => Transaction::STATUS_SUCCEEDED, 'amount' => 1000, 'currency' => 'DKK',
        ]);
        $subscription = $this->checkout();
        $this->fakeCheckout($subscription);
        $this->expectException(InvalidArgumentException::class);

        try {
            $subscription->recordCheckoutCharge();
        } finally {
            $this->assertDatabaseCount('nets_transactions', 1);
            Event::assertNotDispatched(ChargeSucceeded::class);
        }
    }

    private function checkout(array $attributes = []): Subscription
    {
        return $this->subscription(['nets_payment_id' => self::PAYMENT_ID, 'next_charge_at' => now()->addDays(28), ...$attributes]);
    }

    private function fakeCheckout(Subscription $subscription, ?string $key = null, mixed $value = null): void
    {
        $payment = [
            'paymentId' => self::PAYMENT_ID, 'subscription' => ['id' => $subscription->nets_subscription_id],
            'orderDetails' => ['amount' => 1000, 'currency' => 'DKK'],
            'summary' => ['chargedAmount' => 1000],
            'charges' => [['chargeId' => self::CHARGE_ID, 'amount' => 1000, 'created' => now()->subDays(2)->toIso8601String()]],
        ];
        if ($key !== null) {
            data_set($payment, $key, $value);
        }
        Http::fake(['*/v1/payments/'.self::PAYMENT_ID => Http::response(['payment' => $payment])]);
    }
}
