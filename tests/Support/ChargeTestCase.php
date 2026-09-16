<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\Events\ChargeAttemptFailed;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeOutcomeUncertain;
use Udviklr\CashierNets\Events\ChargeReconciliationStalled;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Workbench\App\Models\User;

abstract class ChargeTestCase extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    protected const PAYMENT_ID = '0123456789abcdef0123456789abcdef';

    protected const CHARGE_ID = 'charge_renewal';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15T12:00:00Z'));
        config(['app.url' => 'https://example.com', 'cashier-nets.webhook_authorization' => 'webhook-secret']);
        URL::forceRootUrl('https://example.com');
        URL::forceScheme('https');
        Http::preventStrayRequests();
        Event::fake([ChargeAttemptFailed::class, ChargeOutcomeUncertain::class, ChargeFailed::class, ChargeSucceeded::class, ChargeReconciliationStalled::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function subscription(array $attributes = []): Subscription
    {
        $user = User::create(['name' => 'Charge test', 'email' => Str::uuid().'@example.com', 'password' => 'secret']);

        return $user->netsSubscriptions()->create(array_merge([
            'type' => 'default',
            'nets_subscription_id' => 'sub_'.$user->id,
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 1000,
            'currency' => 'DKK',
            'interval_days' => 30,
            'next_charge_at' => now()->subHour(),
        ], $attributes));
    }

    protected function attempt(Subscription $subscription, array $attributes = []): Transaction
    {
        $key = $attributes['idempotency_key'] ?? 'attempt-'.$subscription->id.'-'.Transaction::query()->count();
        $order = $subscription->chargePayload($subscription->amount, $subscription->currency, ['reference' => 'readable-line'])['order'];
        $order['reference'] = $key;

        return $subscription->transactions()->create(array_merge([
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
            'status' => Transaction::STATUS_PENDING,
            'idempotency_key' => $key,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'frozen_order' => $order,
            'uncertain_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(3),
            'metadata' => ['source' => 'subscription_charge', 'reference' => 'readable-line'],
        ], $attributes));
    }

    protected function payment(Transaction $row, bool $completed = true, array $overrides = []): array
    {
        return ['payment' => array_replace_recursive([
            'paymentId' => self::PAYMENT_ID,
            'subscription' => ['id' => $row->nets_subscription_id],
            'orderDetails' => ['reference' => $row->idempotency_key, 'amount' => $row->amount, 'currency' => $row->currency],
            'created' => now()->subMinutes(2)->toIso8601String(),
            'summary' => $completed ? ['chargedAmount' => $row->amount] : [],
            'charges' => $completed ? [[
                'chargeId' => self::CHARGE_ID,
                'amount' => $row->amount,
                'created' => now()->subMinute()->toIso8601String(),
            ]] : [],
        ], $overrides)];
    }

    protected function webhook(Transaction $row, bool $succeeded = true, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'evt-'.Str::uuid(),
            'event' => $succeeded ? 'payment.charge.created.v2' : 'payment.reservation.failed',
            'timestamp' => now()->subMinute()->toIso8601String(),
            'data' => array_merge([
                'paymentId' => self::PAYMENT_ID,
                'amount' => ['amount' => $row->amount, 'currency' => $row->currency],
            ], $succeeded ? [
                'chargeId' => self::CHARGE_ID,
                'subscriptionId' => $row->nets_subscription_id,
            ] : ['error' => ['code' => '14', 'message' => 'Refused by issuer', 'source' => 'Issuer']]),
        ], $overrides);
    }

    protected function deliver(array $payload)
    {
        return $this->postJson('/nets/webhook', $payload, ['Authorization' => 'webhook-secret']);
    }

    protected function fakePayment(Transaction $row, bool $completed = true, array $overrides = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://test.api.dibspayment.eu/v1/payments/'.self::PAYMENT_ID => Http::response($this->payment($row, $completed, $overrides))]);
    }
}
