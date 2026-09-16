<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ChargeTestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Events\ChargeAttemptFailed;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeOutcomeUncertain;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Exceptions\ChargeBlockedException;
use Udviklr\CashierNets\Exceptions\NetsException;
use Udviklr\CashierNets\Exceptions\UncertainChargeOutcomeException;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Udviklr\CashierNets\WebhookEvent;

class UncertainChargeTest extends ChargeTestCase
{
    #[DataProvider('uncertainResults')]
    public function test_ambiguous_responses_hold_the_attempt_without_spending_the_failure_budget(string $kind): void
    {
        $subscription = $this->subscription();
        $nextChargeAt = $subscription->next_charge_at->toIso8601String();
        config(['cashier-nets.retry_policy.max_attempts' => 1]);
        Http::fake(fn () => match ($kind) {
            'timeout' => throw new ConnectionException('Read timed out'),
            'other' => throw new RuntimeException('Unexpected transport failure'),
            'missing_ids' => Http::response(['paymentId' => self::PAYMENT_ID]),
            'malformed' => Http::response('not-json', 200),
            default => Http::response(['message' => 'Uncertain response'], (int) $kind),
        });

        try {
            $subscription->charge();
            $this->fail('An uncertain result must throw its dedicated exception.');
        } catch (UncertainChargeOutcomeException $exception) {
            $row = $exception->transaction->fresh();
            $this->assertNotNull($exception->getPrevious());
            $this->assertSame(Transaction::STATUS_PENDING, $row->status);
            $this->assertNotNull($row->uncertain_at);
            $this->assertNotEmpty($row->metadata['uncertain_reason']);
            $this->assertTrue($row->held());
        }

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->failed_at);
        $this->assertSame($nextChargeAt, $subscription->next_charge_at->toIso8601String());
        $this->assertTrue($subscription->chargeRetryable());
        $this->assertSame(0, $subscription->transactions()->where('status', Transaction::STATUS_FAILED)->count());
        $this->assertSame(1, $subscription->transactions()->count());
        Event::assertDispatchedTimes(ChargeOutcomeUncertain::class, 1);
        Event::assertNotDispatched(ChargeAttemptFailed::class);
    }

    public static function uncertainResults(): array
    {
        return array_map(fn ($kind) => [$kind], ['timeout', 'other', '500', '503', '408', '429', 'missing_ids', 'malformed']);
    }

    public function test_an_interleaved_second_caller_observes_the_reservation_and_never_posts(): void
    {
        $subscription = $this->subscription();
        $posts = 0;
        $second = null;
        $outerTransactionLevel = DB::transactionLevel();
        Http::fake(function (Request $request) use ($subscription, &$posts, &$second, $outerTransactionLevel) {
            $posts++;
            $this->assertSame($outerTransactionLevel, DB::transactionLevel(), 'The reservation lock must be committed before HTTP.');
            if ($posts === 1) {
                $second = $subscription->fresh()->charge(['reference' => 'changed-between-workers', 'metadata' => ['replace' => true]]);
                $this->assertSame(Transaction::STATUS_PENDING, $second->status);
            }

            return Http::response(['paymentId' => self::PAYMENT_ID, 'chargeId' => self::CHARGE_ID]);
        });

        $first = $subscription->charge(['reference' => 'original', 'metadata' => ['keep' => true]]);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, $posts);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertTrue($first->metadata['keep']);
        $this->assertArrayNotHasKey('replace', $first->metadata);
        $this->assertSame('original', $first->frozen_order['items'][0]['reference']);
    }

    #[DataProvider('pendingKinds')]
    public function test_any_pending_attempt_blocks_new_keys_and_both_schedulers(string $kind, string $status): void
    {
        $subscription = $this->subscription(['status' => $status]);
        if ($status === Subscription::STATUS_PAST_DUE) {
            $this->attempt($subscription, ['status' => Transaction::STATUS_FAILED, 'created_at' => now()->subDays(2), 'billed_at' => now()->subDays(2)]);
        }
        $row = $this->attempt($subscription, match ($kind) {
            'fresh' => ['uncertain_at' => null, 'created_at' => now()],
            'with_ids' => ['uncertain_at' => null, 'nets_payment_id' => self::PAYMENT_ID, 'nets_charge_id' => self::CHARGE_ID],
            default => [],
        });
        Http::fake();

        $this->assertTrue($row->is($subscription->charge(['idempotency_key' => $row->idempotency_key])));
        try {
            $subscription->charge(['idempotency_key' => 'different-key']);
            $this->fail('An unresolved attempt must block a new key.');
        } catch (ChargeBlockedException $exception) {
            $this->assertTrue($row->is($exception->transaction));
            $this->assertSame($kind === 'held' ? 'held' : 'in_flight', $exception->reason);
        }
        $this->assertTrue(CashierNets::subscriptionModel()->dueForChargeCollection(10)->isEmpty());
        $this->assertTrue(CashierNets::subscriptionModel()->dueForRetryCollection(10)->isEmpty());
        $this->artisan('cashier-nets:charge-due')->expectsOutput('Charged 0 due subscriptions.')->assertExitCode(0);
        $this->artisan('cashier-nets:retry-past-due')->expectsOutput('Retried 0 past due subscriptions.')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public static function pendingKinds(): array
    {
        $cases = [];
        foreach (['fresh', 'held', 'with_ids'] as $kind) {
            foreach ([Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE] as $status) {
                $cases[] = [$kind, $status];
            }
        }

        return $cases;
    }

    public function test_the_snapshot_uses_the_attempt_key_and_preserves_readable_and_merchant_references(): void
    {
        $subscription = $this->subscription();
        Http::fake(['*' => Http::sequence()
            ->push(['paymentId' => self::PAYMENT_ID, 'chargeId' => self::CHARGE_ID])
            ->push(['paymentId' => 'other-payment', 'chargeId' => 'other-charge'])]);
        $row = $subscription->charge(['idempotency_key' => 'attempt-key', 'reference' => 'invoice-line', 'my_reference' => 'INV-42']);

        Http::assertSent(function (Request $request) use ($row): bool {
            $payload = $request->data();

            return $payload['order'] === $row->frozen_order
                && $payload['order']['reference'] === 'attempt-key'
                && $payload['order']['items'][0]['reference'] === 'invoice-line'
                && $payload['myReference'] === 'INV-42'
                && $payload['notifications']['webHooks'][0]['authorization'] === 'webhook-secret';
        });
        $this->assertSame('invoice-line', $row->metadata['reference']);
        $this->assertStringNotContainsString('webhook-secret', $row->toJson());
        $this->assertArrayNotHasKey('notifications', $row->frozen_order);

        $other = $this->subscription()->charge();
        $this->assertSame($other->idempotency_key, $other->frozen_order['reference']);
        $this->assertArrayNotHasKey('my_reference', $other->metadata);
        Http::assertSent(fn (Request $request): bool => $request['order']['reference'] === $other->idempotency_key && ! isset($request['myReference']));
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_keys_are_rejected_before_reservation_or_http(mixed $key): void
    {
        Http::fake();
        try {
            $this->subscription()->charge(['idempotency_key' => $key]);
            $this->fail('Expected an invalid key to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Transaction::query()->count());
            Http::assertNothingSent();
        }
    }

    public static function invalidKeys(): array
    {
        return array_map(fn ($key) => [$key], ['', str_repeat('a', 64), ' padded', 'padded ', "bad\rkey", 'bad<key', 'bad>key', 'bad\\key', 123, "\u{00a0}padded", "bad\u{0085}key", "bad\xffkey"]);
    }

    public function test_a_terminal_key_cannot_be_reopened_or_reused_by_another_subscription(): void
    {
        $subscription = $this->subscription();
        $row = $this->attempt($subscription, ['status' => Transaction::STATUS_SUCCEEDED, 'uncertain_at' => null]);
        Http::fake();
        $this->assertTrue($row->is($subscription->charge(['idempotency_key' => $row->idempotency_key])));
        $this->assertSame(Transaction::STATUS_SUCCEEDED, $row->fresh()->status);

        try {
            $this->subscription()->charge(['idempotency_key' => $row->idempotency_key]);
            $this->fail('A key cannot belong to two subscriptions.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_a_webhook_that_wins_the_race_is_not_regressed_by_the_timeout_catch(): void
    {
        $subscription = $this->subscription();
        Http::fake(function (Request $request) use ($subscription) {
            $row = $subscription->transactions()->firstOrFail();
            if ($request->method() === 'GET') {
                return Http::response($this->payment($row));
            }
            $this->deliver($this->webhook($row))->assertOk();
            throw new ConnectionException('Late timeout after the webhook');
        });

        $row = $subscription->charge();
        $this->assertSame(Transaction::STATUS_SUCCEEDED, $row->status);
        $this->assertNull($row->uncertain_at);
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
        Event::assertNotDispatched(ChargeOutcomeUncertain::class);
        Event::assertNotDispatched(ChargeAttemptFailed::class);
    }

    #[DataProvider('declineCandidates')]
    public function test_decline_codes_are_immediate_and_message_ids_require_verified_identity(string $scenario): void
    {
        $subscription = $this->subscription();
        $message = match ($scenario) {
            'missing' => 'Refused by issuer',
            'malformed' => 'Payment 0123456789abcdef0123456789abcdef0 refused',
            'multiple' => self::PAYMENT_ID.' and aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refused',
            default => 'Direct charge failed for payment id: '.self::PAYMENT_ID.'. Refused by issuer',
        };
        Http::fake(function (Request $request) use ($subscription, $message, $scenario) {
            if ($request->method() === 'POST') {
                return Http::response(['code' => '14', 'source' => 'Issuer', 'message' => $message], 402);
            }
            $row = $subscription->transactions()->firstOrFail();
            $this->assertSame('14', $row->failure_code, 'Persist the code before the optional lookup.');
            $this->assertFalse($row->retryable());

            return $scenario === 'get_fails'
                ? Http::response([], 503)
                : Http::response($this->payment($row, false, $scenario === 'wrong_key' ? ['orderDetails' => ['reference' => 'another-attempt']] : []));
        });
        try {
            $subscription->charge();
            $this->fail('The decline must propagate.');
        } catch (NetsException $exception) {
            $this->assertSame(402, $exception->getCode());
        }

        $row = $subscription->transactions()->firstOrFail();
        $this->assertSame('14', $row->failure_code);
        $this->assertSame('Issuer', $row->metadata['failure_source']);
        $this->assertSame(402, $row->metadata['http_status']);
        $this->assertFalse($row->retryable());
        $this->assertSame($scenario === 'valid' ? self::PAYMENT_ID : null, $row->nets_payment_id);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->fresh()->status);
        Event::assertDispatchedTimes(ChargeAttemptFailed::class, 1);
        Event::assertNotDispatched(ChargeOutcomeUncertain::class);

        $this->fakePayment($row, false);
        $this->deliver($this->webhook($row, false))->assertOk();
        $this->assertSame(1, $subscription->transactions()->count());
        $this->assertSame(self::PAYMENT_ID, $row->fresh()->nets_payment_id);
        Event::assertNotDispatched(ChargeFailed::class);
    }

    public static function declineCandidates(): array
    {
        return array_map(fn ($kind) => [$kind], ['valid', 'missing', 'malformed', 'multiple', 'wrong_key', 'get_fails']);
    }

    public function test_a_structured_decline_payment_id_is_validated_and_its_charge_id_is_retained(): void
    {
        $subscription = $this->subscription();
        Http::fake(function (Request $request) use ($subscription) {
            if ($request->method() === 'POST') {
                return Http::response(['code' => '14', 'message' => 'Declined', 'paymentId' => self::PAYMENT_ID, 'chargeId' => self::CHARGE_ID], 402);
            }

            return Http::response($this->payment($subscription->transactions()->firstOrFail(), false));
        });
        try {
            $subscription->charge();
            $this->fail('Expected decline.');
        } catch (NetsException) {
            $row = $subscription->transactions()->firstOrFail();
            $this->assertSame(self::PAYMENT_ID, $row->nets_payment_id);
            $this->assertSame(self::CHARGE_ID, $row->nets_charge_id);
        }
    }

    public function test_a_failure_webhook_upgrades_an_http_fallback_code_without_counting_or_emitting_again(): void
    {
        $subscription = $this->subscription(['status' => Subscription::STATUS_PAST_DUE]);
        $row = $this->attempt($subscription, ['status' => Transaction::STATUS_FAILED, 'failure_code' => '402', 'uncertain_at' => null]);
        $this->fakePayment($row, false);

        $this->deliver($this->webhook($row, false))->assertOk();
        $this->assertSame('14', $row->fresh()->failure_code);
        $this->assertFalse($row->fresh()->retryable());
        $this->assertSame(1, $subscription->transactions()->where('status', Transaction::STATUS_FAILED)->count());
        Event::assertNotDispatched(ChargeFailed::class);
    }

    public function test_success_and_failure_callbacks_adopt_by_retrieved_payment_even_without_event_subscription_id(): void
    {
        foreach ([true, false] as $succeeded) {
            $subscription = $this->subscription();
            $row = $this->attempt($subscription);
            $this->fakePayment($row, $succeeded);
            $payload = $this->webhook($row, $succeeded);
            unset($payload['data']['subscriptionId']);

            $this->deliver($payload)->assertOk();
            $this->assertSame($succeeded ? Transaction::STATUS_SUCCEEDED : Transaction::STATUS_FAILED, $row->fresh()->status);
            $this->assertNull($row->fresh()->uncertain_at);
            $this->assertSame(1, $subscription->transactions()->count());
            $this->assertSame(self::PAYMENT_ID, $row->fresh()->nets_payment_id);
            // Keep provider identifiers unique across independent subcases.
            $row->delete();
        }
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/charges/status'));
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
        Event::assertDispatchedTimes(ChargeFailed::class, 1);
    }

    public function test_payment_created_supplies_identity_without_releasing_a_held_attempt(): void
    {
        $row = $this->attempt($this->subscription());
        $this->fakePayment($row, false);
        $payload = $this->webhook($row, false, ['event' => 'payment.created']);
        unset($payload['data']['error']);

        $this->deliver($payload)->assertOk();
        $this->assertSame(Transaction::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame(self::PAYMENT_ID, $row->fresh()->nets_payment_id);
        $this->assertTrue($row->fresh()->held());
        Event::assertNotDispatched(ChargeFailed::class);
        Event::assertNotDispatched(ChargeSucceeded::class);
    }

    public function test_late_payment_created_and_success_events_cannot_reopen_a_failed_attempt(): void
    {
        $subscription = $this->subscription(['status' => Subscription::STATUS_PAST_DUE]);
        $row = $this->attempt($subscription, ['status' => Transaction::STATUS_FAILED, 'uncertain_at' => null, 'failure_code' => '14', 'billed_at' => now()->subHour()]);
        $this->fakePayment($row);
        $this->deliver($this->webhook($row, false, ['event' => 'payment.created']))->assertOk();
        $this->deliver($this->webhook($row))->assertOk();
        $this->assertSame(Transaction::STATUS_FAILED, $row->fresh()->status);
        $this->assertSame('14', $row->fresh()->failure_code);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->fresh()->status);
        $this->assertTrue($row->fresh()->billed_at->equalTo(now()->subHour()));
        Event::assertNotDispatched(ChargeSucceeded::class);
        Event::assertNotDispatched(ChargeFailed::class);
    }

    public function test_a_charge_event_must_name_a_charge_present_in_the_verified_payment(): void
    {
        $row = $this->attempt($this->subscription());
        $this->fakePayment($row);
        $this->deliver($this->webhook($row, true, ['data' => ['chargeId' => 'unrelated-charge']]))->assertServerError();
        $this->assertTrue($row->fresh()->held());
        $this->assertSame(1, Transaction::query()->count());
        Event::assertNotDispatched(ChargeSucceeded::class);
    }

    public function test_legacy_checkout_charge_events_still_fill_subscription_ids_and_missing_financial_fields(): void
    {
        $subscription = $this->subscription(['nets_subscription_id' => null, 'nets_payment_id' => self::PAYMENT_ID]);
        $row = $this->attempt($subscription, ['frozen_order' => null, 'nets_payment_id' => self::PAYMENT_ID,
            'nets_charge_id' => self::CHARGE_ID, 'amount' => null, 'currency' => null]);
        $payload = $this->webhook($row, true, ['data' => [
            'subscriptionId' => 'sub_from_checkout_charge', 'amount' => ['amount' => 1000, 'currency' => 'DKK'],
        ]]);

        $this->deliver($payload)->assertOk();
        $this->assertSame('sub_from_checkout_charge', $subscription->fresh()->nets_subscription_id);
        $this->assertSame('sub_from_checkout_charge', $row->fresh()->nets_subscription_id);
        $this->assertSame(1000, $row->fresh()->amount);
        $this->assertSame('DKK', $row->fresh()->currency);
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
        Http::assertNothingSent();
    }

    #[DataProvider('identityMismatches')]
    public function test_a_provider_payment_with_mismatched_identity_cannot_adopt_an_attempt(string $path, mixed $value): void
    {
        $row = $this->attempt($this->subscription());
        $payment = $this->payment($row);
        data_set($payment, 'payment.'.$path, $value);
        Http::fake(['*' => Http::response($payment)]);

        $this->deliver($this->webhook($row));
        $this->assertSame(Transaction::STATUS_PENDING, $row->fresh()->status);
        $this->assertNull($row->fresh()->nets_payment_id);
        $this->assertNull($row->fresh()->nets_charge_id);
    }

    public static function identityMismatches(): array
    {
        return [['paymentId', 'other-payment'], ['subscription.id', 'other-subscription'], ['orderDetails.reference', 'another-attempt'], ['orderDetails.amount', 999], ['orderDetails.currency', 'EUR']];
    }

    public function test_an_unavailable_payment_get_leaves_the_webhook_retryable_without_creating_an_orphan(): void
    {
        $row = $this->attempt($this->subscription());
        Http::fake(['*' => Http::response([], 503)]);
        $payload = $this->webhook($row);

        $this->deliver($payload)->assertServerError();
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(Transaction::STATUS_PENDING, $row->fresh()->status);
        $this->assertFalse(WebhookEvent::where('nets_event_id', $payload['id'])->firstOrFail()->processed());

        $this->fakePayment($row);
        $this->deliver($payload)->assertOk();
        Event::assertDispatchedTimes(ChargeSucceeded::class, 1);
    }

    #[DataProvider('heldStates')]
    public function test_held_state_distinguishes_fresh_payment_only_and_charge_identified_attempts(int $age, bool $uncertain, ?string $chargeId, bool $held): void
    {
        $row = $this->attempt($this->subscription(), ['created_at' => now()->subSeconds($age), 'uncertain_at' => $uncertain ? now() : null, 'nets_charge_id' => $chargeId, 'nets_payment_id' => self::PAYMENT_ID]);
        $this->assertSame($held, $row->held());
    }

    public static function heldStates(): array
    {
        return [[0, false, null, false], [121, false, null, true], [0, true, null, true], [3600, true, self::CHARGE_ID, false]];
    }
}
