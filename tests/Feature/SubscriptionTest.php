<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Subscription;
use Workbench\App\Models\User;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_it_can_check_trial_and_grace_periods(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $trialing = $user->netsSubscriptions()->create([
            'type' => 'trial',
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => Carbon::tomorrow(),
        ]);

        $canceledOnGracePeriod = $user->netsSubscriptions()->create([
            'type' => 'grace',
            'status' => Subscription::STATUS_CANCELED,
            'ends_at' => Carbon::tomorrow(),
        ]);

        $this->assertTrue($trialing->onTrial());
        $this->assertFalse($trialing->hasExpiredTrial());
        $this->assertTrue($trialing->valid());
        $this->assertTrue($canceledOnGracePeriod->canceled());
        $this->assertTrue($canceledOnGracePeriod->onGracePeriod());
        $this->assertTrue($canceledOnGracePeriod->valid());
    }

    public function test_past_due_validity_is_configurable(): void
    {
        $subscription = new Subscription([
            'status' => Subscription::STATUS_PAST_DUE,
        ]);

        $this->assertFalse($subscription->valid());

        CashierNets::$deactivatePastDue = false;

        try {
            $this->assertTrue($subscription->valid());
        } finally {
            CashierNets::$deactivatePastDue = true;
        }
    }

    public function test_due_for_charge_scope_filters_due_subscriptions(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $due = $user->netsSubscriptions()->create([
            'type' => 'due',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::yesterday(),
        ]);

        $user->netsSubscriptions()->create([
            'type' => 'later',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::tomorrow(),
        ]);

        $this->assertSame([$due->id], Subscription::query()->dueForCharge()->pluck('id')->all());
    }

    public function test_due_for_charge_selection_skips_canceled_expired_and_ended_subscriptions(): void
    {
        $user = $this->createUser();

        $due = $user->netsSubscriptions()->create([
            'type' => 'due',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::yesterday(),
        ]);

        $endingLater = $user->netsSubscriptions()->create([
            'type' => 'ending-later',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::yesterday(),
            'ends_at' => Carbon::tomorrow(),
        ]);

        $user->netsSubscriptions()->create([
            'type' => 'canceled',
            'status' => Subscription::STATUS_CANCELED,
            'next_charge_at' => Carbon::yesterday(),
        ]);

        $user->netsSubscriptions()->create([
            'type' => 'expired',
            'status' => Subscription::STATUS_EXPIRED,
            'next_charge_at' => Carbon::yesterday(),
        ]);

        $user->netsSubscriptions()->create([
            'type' => 'ended',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::yesterday(),
            'ends_at' => Carbon::yesterday(),
        ]);

        $selected = CashierNets::subscriptionModel()->dueForChargeCollection(10);

        $this->assertSame([$due->id, $endingLater->id], $selected->pluck('id')->sort()->values()->all());
    }

    public function test_cancel_and_resume_round_trip_keeps_the_next_charge_date(): void
    {
        $nextChargeAt = Carbon::tomorrow();

        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => $nextChargeAt,
        ]);

        $subscription->cancel();

        $this->assertTrue($subscription->canceled());
        $this->assertNotNull($subscription->ends_at);
        $this->assertFalse($subscription->valid());
        $this->assertTrue($subscription->next_charge_at?->equalTo($nextChargeAt));
        $this->assertFalse($subscription->dueForCharge());

        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertNull($subscription->ends_at);
        $this->assertTrue($subscription->next_charge_at?->equalTo($nextChargeAt));
    }

    public function test_cancel_with_a_future_end_date_keeps_a_grace_period(): void
    {
        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::yesterday(),
        ]);

        $subscription->cancel(Carbon::tomorrow());

        $this->assertTrue($subscription->canceled());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertTrue($subscription->valid());
        $this->assertFalse($subscription->ended());
    }

    public function test_expire_is_terminal(): void
    {
        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::tomorrow(),
        ]);

        $subscription->expire();

        $this->assertTrue($subscription->expired());
        $this->assertTrue($subscription->ended());
        $this->assertNull($subscription->next_charge_at);
        $this->assertNotNull($subscription->ends_at);

        $this->expectException(\RuntimeException::class);

        $subscription->resume();
    }

    public function test_expire_keeps_an_existing_end_date(): void
    {
        $endsAt = Carbon::yesterday();

        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_CANCELED,
            'ends_at' => $endsAt,
        ]);

        $subscription->expire();

        $this->assertTrue($subscription->ends_at?->equalTo($endsAt));
    }

    public function test_resume_from_a_non_canceled_subscription_throws(): void
    {
        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_ACTIVE,
            'next_charge_at' => Carbon::tomorrow(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only canceled subscriptions may be resumed.');

        $subscription->resume();
    }

    public function test_resume_without_a_next_charge_date_throws(): void
    {
        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_CANCELED,
            'next_charge_at' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A resumed subscription requires a next charge date.');

        $subscription->resume();
    }

    public function test_charging_a_canceled_subscription_throws(): void
    {
        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_CANCELED,
            'nets_subscription_id' => 'sub_123',
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The subscription cannot be charged in its current status.');

        $subscription->charge();
    }

    public function test_charging_an_ended_subscription_throws(): void
    {
        $subscription = $this->createUser()->netsSubscriptions()->create([
            'type' => 'main',
            'status' => Subscription::STATUS_ACTIVE,
            'nets_subscription_id' => 'sub_123',
            'amount' => 9900,
            'currency' => 'DKK',
            'ends_at' => Carbon::yesterday(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The subscription has ended and cannot be charged.');

        $subscription->charge();
    }

    /**
     * Create a workbench user to own subscriptions.
     */
    protected function createUser(): User
    {
        return User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);
    }
}
