<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ChargeTestCase;
use Udviklr\CashierNets\Charges\ChargeFinalizer;
use Udviklr\CashierNets\Events\ChargeAttemptFailed;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeReconciliationStalled;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Exceptions\UncertainChargeOutcomeException;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\WebhookEvent;

class ChargeReconciliationTest extends ChargeTestCase
{
    public function test_a_failing_reconciliation_listener_rolls_back_and_can_retry_the_same_outcome(): void
    {
        $row = $this->attempt($this->subscription(), ['nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'created_at' => now()->subHour()]);
        $this->fakePayment($row);
        Event::swap(new Dispatcher($this->app));
        $shouldFail = true;
        $calls = 0;
        Event::listen(ChargeSucceeded::class, function () use (&$shouldFail, &$calls) {
            $calls++;
            if ($shouldFail) {
                throw new \RuntimeException('Consumer invoice creation failed');
            }
        });

        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(1);
        $this->assertSame(Transaction::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame(0, WebhookEvent::whereNotNull('processed_at')->count());
        $shouldFail = false;
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->assertSame(Transaction::STATUS_SUCCEEDED, $row->fresh()->status);
        $this->assertSame(1, WebhookEvent::whereNotNull('processed_at')->count());
        $this->assertSame(2, $calls);
    }

    #[DataProvider('uniqueIdentifiers')]
    public function test_database_constraints_reject_duplicate_attempt_and_charge_identities(string $column): void
    {
        $this->attempt($this->subscription(), [$column => 'duplicate']);
        $other = $this->subscription();
        $this->expectException(QueryException::class);
        $this->attempt($other, [$column => 'duplicate']);
    }

    public static function uniqueIdentifiers(): array
    {
        return [['idempotency_key'], ['nets_charge_id']];
    }

    public function test_status_discovery_finalizes_once_with_provider_time_and_the_existing_event_shape(): void
    {
        $subscription = $this->subscription();
        $row = $this->attempt($subscription);
        $payment = $this->payment($row);
        Http::fake(function (Request $request) use ($row, $payment) {
            if (str_ends_with($request->url(), '/charges/status')) {
                $this->assertTrue($request->hasHeader('Idempotency-Key', $row->idempotency_key));

                return Http::response(['completed' => true, 'paymentId' => self::PAYMENT_ID, 'chargeId' => self::CHARGE_ID]);
            }

            return Http::response($payment);
        });

        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $row->refresh();
        $this->assertSame(Transaction::STATUS_SUCCEEDED, $row->status);
        $this->assertSame(now()->subMinute()->toIso8601String(), $row->billed_at->toIso8601String());
        $this->assertSame(now()->subMinute()->addDays(30)->toIso8601String(), $subscription->fresh()->next_charge_at->toIso8601String());
        $event = WebhookEvent::firstOrFail();
        $this->assertSame('reconcile:'.$row->id.':'.self::CHARGE_ID, $event->nets_event_id);
        $this->assertSame('reconcile', $event->source);
        $this->assertTrue($event->processed());
        Event::assertDispatched(ChargeSucceeded::class, fn ($event): bool => $event->transaction->is($row)
            && $event->payload->paymentId() === self::PAYMENT_ID
            && $event->payload->chargeId() === self::CHARGE_ID
            && $event->payload->subscriptionId() === $row->nets_subscription_id
            && $event->payload->amount() === $row->amount
            && $event->payload->currency() === $row->currency
            && $event->payload->occurredAt()->equalTo($row->billed_at));

        $this->deliver($this->webhook($row, true, ['timestamp' => now()->toIso8601String(), 'data' => ['invoiceNumber' => 'INV-99']]))->assertOk();
        $this->assertSame('INV-99', $row->fresh()->metadata['invoice_number']);
        $this->assertSame($row->billed_at->toIso8601String(), $row->fresh()->billed_at->toIso8601String());
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_incomplete_status_with_verified_ids_moves_to_awaiting_webhook(): void
    {
        $row = $this->attempt($this->subscription());
        Http::fake(fn (Request $request) => Http::response(str_ends_with($request->url(), '/charges/status')
            ? ['completed' => false, 'paymentId' => self::PAYMENT_ID, 'chargeId' => self::CHARGE_ID]
            : $this->payment($row, false)));

        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $row->refresh();
        $this->assertSame(Transaction::STATUS_PENDING, $row->status);
        $this->assertSame(self::CHARGE_ID, $row->nets_charge_id);
        $this->assertFalse($row->held());
        $this->assertNull($row->uncertain_at);
        Event::assertNotDispatched(ChargeSucceeded::class);
    }

    #[DataProvider('unresolvedStatusResults')]
    public function test_inconclusive_status_never_releases_the_hold_and_alerts_once_from_creation(string $kind): void
    {
        $row = $this->attempt($this->subscription(), ['created_at' => now()->subMinutes(61), 'uncertain_at' => now()]);
        Http::fake(fn () => match ($kind) {
            'timeout' => throw new ConnectionException('Timed out'),
            'incomplete' => Http::response(['completed' => false]),
            default => Http::response([], (int) $kind),
        });

        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->assertTrue($row->fresh()->held());
        $this->assertArrayHasKey('reconciliation_stalled_at', $row->fresh()->metadata);
        Event::assertDispatchedTimes(ChargeReconciliationStalled::class, 1);
        Event::assertNotDispatched(ChargeFailed::class);
        Event::assertNotDispatched(ChargeAttemptFailed::class);
    }

    public static function unresolvedStatusResults(): array
    {
        return [['404'], ['503'], ['timeout'], ['incomplete']];
    }

    #[DataProvider('paymentStates')]
    public function test_stale_identified_rows_need_terminal_payment_evidence(string $state): void
    {
        $row = $this->attempt($this->subscription(), ['nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'uncertain_at' => null, 'created_at' => now()->subMinutes(61)]);
        $this->fakePayment($row, $state === 'complete', $state === 'pending' ? ['charges' => [['chargeId' => self::CHARGE_ID, 'amount' => $row->amount, 'status' => 'pending']]] : []);

        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->assertSame($state === 'complete' ? Transaction::STATUS_SUCCEEDED : Transaction::STATUS_PENDING, $row->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/charges/status'));
        if ($state === 'complete') {
            Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
            Event::assertNotDispatched(ChargeReconciliationStalled::class);
        } else {
            Event::assertNotDispatched(ChargeSucceeded::class);
            Event::assertNotDispatched(ChargeFailed::class);
            Event::assertDispatchedTimes(ChargeReconciliationStalled::class, 1);
        }
    }

    public static function paymentStates(): array
    {
        return [['complete'], ['pending'], ['empty']];
    }

    public function test_manual_payment_resolution_rejects_a_previous_renewal_without_any_force_bypass(): void
    {
        $row = $this->attempt($this->subscription());
        $this->fakePayment($row, true, ['orderDetails' => ['reference' => 'previous-renewal-key']]);

        $this->artisan('cashier-nets:resolve-charge', ['transaction' => $row->id, '--payment-id' => self::PAYMENT_ID])->assertExitCode(1);
        $this->assertTrue($row->fresh()->held());
        $this->assertNull($row->fresh()->nets_payment_id);
        Event::assertNotDispatched(ChargeSucceeded::class);
    }

    public function test_manual_payment_resolution_uses_verified_payment_without_status_discovery(): void
    {
        $row = $this->attempt($this->subscription());
        $this->fakePayment($row);

        $this->artisan('cashier-nets:resolve-charge', ['transaction' => $row->id, '--payment-id' => self::PAYMENT_ID])->assertExitCode(0);
        $this->assertSame(Transaction::STATUS_SUCCEEDED, $row->fresh()->status);
        $this->assertSame('manual', WebhookEvent::firstOrFail()->source);
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/charges/status'));
    }

    public function test_an_identified_empty_payment_needs_operator_confirmation_and_a_reason(): void
    {
        $row = $this->attempt($this->subscription());
        $this->fakePayment($row, false);
        $options = ['transaction' => $row->id, '--payment-id' => self::PAYMENT_ID];

        $this->artisan('cashier-nets:resolve-charge', $options)->assertExitCode(0);
        $this->assertTrue($row->fresh()->held());
        Event::assertNotDispatched(ChargeFailed::class);
        $this->artisan('cashier-nets:resolve-charge', $options + ['--failed' => true])->assertExitCode(1);
        $this->assertTrue($row->fresh()->held());
        $this->artisan('cashier-nets:resolve-charge', $options + ['--failed' => true, '--reason' => 'Declined in portal, matched by order reference'])->assertExitCode(0);
        $row->refresh();
        $this->assertSame(Transaction::STATUS_FAILED, $row->status);
        $this->assertNull($row->failure_code);
        $this->assertSame('Declined in portal, matched by order reference', $row->metadata['operator_reason']);
        $this->assertTrue($row->metadata['provider_timestamp_missing']);
        Event::assertDispatchedTimes(ChargeAttemptFailed::class, 1);
    }

    public function test_manual_no_charge_confirmation_allows_a_fresh_key_in_the_same_period(): void
    {
        $subscription = $this->subscription();
        Http::fake(['*' => Http::sequence()->push([], 500)->push(['paymentId' => self::PAYMENT_ID, 'chargeId' => self::CHARGE_ID])]);
        try {
            $subscription->charge();
        } catch (UncertainChargeOutcomeException $exception) {
            $row = $exception->transaction;
        }

        $this->artisan('cashier-nets:resolve-charge', ['transaction' => $row->id, '--failed' => true])->assertExitCode(0);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->fresh()->status);
        $retry = $subscription->fresh()->charge();
        $this->assertNotSame($row->idempotency_key, $retry->idempotency_key);
        $this->assertSame(2, $subscription->transactions()->count());
        Event::assertDispatchedTimes(ChargeAttemptFailed::class, 1);
    }

    #[DataProvider('identifiedFailureOptions')]
    public function test_operator_failure_confirmation_cannot_release_an_identified_pending_charge(array $options): void
    {
        $subscription = $this->subscription();
        $row = $this->attempt($subscription, ['nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'uncertain_at' => null]);
        $before = $row->fresh()->getAttributes();
        $nextChargeAt = $subscription->next_charge_at->toIso8601String();
        if (isset($options['--payment-id'])) {
            $this->fakePayment($row, false);
        }

        $this->artisan('cashier-nets:resolve-charge', ['transaction' => $row->id, '--failed' => true] + $options)
            ->expectsOutput('Cannot mark this attempt failed while it has a charge ID; reconcile the provider outcome instead.')
            ->assertExitCode(1);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->failed_at);
        $this->assertSame($nextChargeAt, $subscription->fresh()->next_charge_at->toIso8601String());
        Event::assertNotDispatched(ChargeAttemptFailed::class);
        Event::assertNotDispatched(ChargeFailed::class);
        if (! isset($options['--payment-id'])) {
            Http::assertNothingSent();
        }
    }

    public static function identifiedFailureOptions(): array
    {
        return [[[]], [['--reason' => 'Checked portal']], [['--payment-id' => self::PAYMENT_ID, '--reason' => 'Checked portal']]];
    }

    public function test_failure_confirmation_rechecks_the_charge_id_after_locking_a_stale_attempt(): void
    {
        $row = $this->attempt($this->subscription());
        $row->newQuery()->whereKey($row->id)->update(['nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'uncertain_at' => null]);
        $this->assertNull($row->nets_charge_id);

        try {
            app(ChargeFinalizer::class)->confirmFailed($row, null);
            $this->fail('A concurrently identified charge must not be marked failed.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('charge ID', $exception->getMessage());
        }
        $this->assertSame(Transaction::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame(self::CHARGE_ID, $row->fresh()->nets_charge_id);
        Event::assertNotDispatched(ChargeAttemptFailed::class);
        Http::assertNothingSent();
    }

    public function test_an_already_owned_charge_cancels_the_held_duplicate_without_second_subscription_effects(): void
    {
        $subscription = $this->subscription();
        $canonical = $this->attempt($subscription, ['status' => Transaction::STATUS_SUCCEEDED, 'nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'billed_at' => now()->subHour()]);
        $held = $this->attempt($subscription);
        $before = $subscription->next_charge_at->toIso8601String();
        $this->fakePayment($held);

        $this->artisan('cashier-nets:resolve-charge', ['transaction' => $held->id, '--payment-id' => self::PAYMENT_ID])->assertExitCode(0);
        $this->assertSame(Transaction::STATUS_CANCELED, $held->fresh()->status);
        $this->assertSame($canonical->id, $held->fresh()->metadata['superseded_by']);
        $this->assertSame($before, $subscription->fresh()->next_charge_at->toIso8601String());
        Event::assertNotDispatched(ChargeSucceeded::class);
    }

    public function test_a_webhook_can_supersede_a_held_attempt_when_a_legacy_row_already_owns_the_charge(): void
    {
        $subscription = $this->subscription();
        $canonical = $this->attempt($subscription, ['status' => Transaction::STATUS_SUCCEEDED, 'nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'frozen_order' => null]);
        $held = $this->attempt($subscription);
        $this->fakePayment($held);

        $this->deliver($this->webhook($held))->assertOk();
        $this->assertSame(Transaction::STATUS_CANCELED, $held->fresh()->status);
        $this->assertSame($canonical->id, $held->fresh()->metadata['superseded_by']);
        Event::assertNotDispatched(ChargeSucceeded::class);
    }

    public function test_alerting_does_not_depend_on_the_webhook_grace_or_successful_identity_verification(): void
    {
        config(['cashier-nets.reconcile.alert_after_minutes' => 5, 'cashier-nets.reconcile.webhook_grace_minutes' => 30]);
        $row = $this->attempt($this->subscription(), ['nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'uncertain_at' => null, 'created_at' => now()->subMinutes(6)]);
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        Event::assertDispatchedTimes(ChargeReconciliationStalled::class, 1);
        Http::assertNothingSent();

        config(['cashier-nets.reconcile.webhook_grace_minutes' => 1]);
        $this->fakePayment($row, true, ['orderDetails' => ['reference' => 'wrong-key']]);
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(1);
        $this->assertSame(Transaction::STATUS_PENDING, $row->fresh()->status);
        Event::assertDispatchedTimes(ChargeReconciliationStalled::class, 1);
    }

    public function test_a_verified_payment_decline_uses_the_existing_event_and_preserves_its_code_on_manual_confirmation(): void
    {
        $row = $this->attempt($this->subscription(), ['nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID, 'created_at' => now()->subHour()]);
        $this->fakePayment($row, false, ['charges' => [[
            'chargeId' => self::CHARGE_ID, 'amount' => $row->amount, 'status' => 'failed',
            'error' => ['code' => '14', 'message' => 'Declined'],
        ]]]);
        $this->artisan('cashier-nets:reconcile-charges')->assertExitCode(0);
        $this->assertSame(Transaction::STATUS_FAILED, $row->fresh()->status);
        $this->assertSame('14', $row->fresh()->failure_code);
        $this->assertTrue($row->fresh()->metadata['provider_timestamp_missing']);
        $this->assertSame(now()->toIso8601String(), $row->fresh()->billed_at->toIso8601String());
        Event::assertDispatchedTimes(ChargeFailed::class, 1);
        $this->artisan('cashier-nets:resolve-charge', ['transaction' => $row->id, '--failed' => true])->assertExitCode(0);
        $this->assertSame('14', $row->fresh()->failure_code);
        Event::assertNotDispatched(ChargeAttemptFailed::class);
    }

    public function test_uncertain_listing_includes_held_and_stale_identified_rows_only(): void
    {
        $held = $this->attempt($this->subscription());
        $stale = $this->attempt($this->subscription(), ['nets_charge_id' => self::CHARGE_ID, 'created_at' => now()->subMinutes(31)]);
        $fresh = $this->attempt($this->subscription(), ['uncertain_at' => null, 'created_at' => now()]);

        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, $this->artisan('cashier-nets:uncertain-charges'));
        $output = Artisan::output();
        $this->assertStringContainsString($held->idempotency_key, $output);
        $this->assertStringContainsString($stale->idempotency_key, $output);
        $this->assertStringContainsString('readable-line', $output);
        $this->assertStringNotContainsString($fresh->idempotency_key, $output);
        Http::assertNothingSent();
    }
}
