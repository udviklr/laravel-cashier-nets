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
}
