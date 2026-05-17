<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\Customer;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Workbench\App\Models\User;

class BillableTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_a_billable_model_can_create_a_nets_customer(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $customer = $user->createAsNetsCustomer([
            'nets_customer_id' => 'cus_123',
            'trial_ends_at' => Carbon::tomorrow(),
        ]);

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertTrue($user->fresh()->onGenericTrial());
        $this->assertDatabaseHas('nets_customers', [
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'nets_customer_id' => 'cus_123',
            'email' => 'taylor@example.com',
        ]);
    }

    public function test_a_billable_model_can_check_subscription_state(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $subscription = $user->netsSubscriptions()->create([
            'type' => 'main',
            'nets_subscription_id' => 'sub_123',
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => Carbon::yesterday(),
        ]);

        $this->assertTrue($user->subscribed('main'));
        $this->assertFalse($user->subscribed());
        $this->assertSame($subscription->id, $user->netsSubscription('main')?->id);
        $this->assertTrue($subscription->valid());
        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->dueForCharge());
    }

    public function test_a_billable_model_can_relate_transactions(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $transaction = $user->netsTransactions()->create([
            'nets_payment_id' => 'pay_123',
            'nets_charge_id' => 'chg_123',
            'status' => Transaction::STATUS_SUCCEEDED,
            'amount' => 1250,
            'currency' => 'EUR',
            'billed_at' => Carbon::now(),
        ]);

        $this->assertTrue($transaction->succeeded());
        $this->assertFalse($transaction->failed());
        $this->assertSame(1250, $transaction->rawAmount());
        $this->assertCount(1, $user->netsTransactions);
    }
}
