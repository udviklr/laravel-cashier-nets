<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\Events\RefundCompleted;
use Udviklr\CashierNets\Events\RefundFailed;
use Udviklr\CashierNets\Events\RefundInitiated;
use Udviklr\CashierNets\Refund;
use Udviklr\CashierNets\Transaction;
use Workbench\App\Models\User;

class RefundWebhookTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_a_completed_full_refund_marks_the_transaction_refunded(): void
    {
        Event::fake([RefundCompleted::class]);

        $transaction = $this->createTransaction();

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_refund_completed'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'nets_charge_id' => 'charge_123',
            'nets_payment_id' => 'pay_123',
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);

        Event::assertDispatched(RefundCompleted::class, function (RefundCompleted $event) use ($transaction): bool {
            return $event->payload->refundId() === 'refund_123'
                && $event->transaction?->is($transaction)
                && $event->refund()?->status === Refund::STATUS_COMPLETED;
        });
    }

    public function test_a_partial_completed_refund_keeps_the_transaction_succeeded(): void
    {
        $transaction = $this->createTransaction();

        $payload = $this->refundPayload('payment.refund.completed', 'evt_refund_partial');
        $payload['data']['amount']['amount'] = '2500';

        $this->postJson('/nets/webhook', $payload)->assertOk();

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 2500,
        ]);

        $this->assertSame(Transaction::STATUS_SUCCEEDED, $transaction->fresh()->status);
    }

    public function test_an_initiated_refund_is_recorded_as_pending(): void
    {
        Event::fake([RefundInitiated::class]);

        $transaction = $this->createTransaction();

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.initiated', 'evt_refund_initiated'))
            ->assertOk();

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_PENDING,
        ]);

        $this->assertSame(Transaction::STATUS_SUCCEEDED, $transaction->fresh()->status);

        Event::assertDispatched(RefundInitiated::class);
    }

    public function test_a_failed_refund_is_recorded_with_the_error(): void
    {
        Event::fake([RefundFailed::class]);

        $transaction = $this->createTransaction();

        $payload = $this->refundPayload('payment.refund.failed', 'evt_refund_failed');
        $payload['data']['error'] = ['code' => 'REFUND_REJECTED', 'message' => 'Refund was rejected.'];

        $this->postJson('/nets/webhook', $payload)->assertOk();

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_FAILED,
            'failure_code' => 'REFUND_REJECTED',
            'failure_message' => 'Refund was rejected.',
        ]);

        $this->assertSame(Transaction::STATUS_SUCCEEDED, $transaction->fresh()->status);

        Event::assertDispatched(RefundFailed::class);
    }

    public function test_completing_a_locally_initiated_refund_updates_the_existing_row(): void
    {
        $transaction = $this->createTransaction();

        // A refund initiated through Transaction::refund() leaves a pending row
        // keyed by its idempotency key with the Nets refund id already stored.
        $transaction->refunds()->create([
            'billable_type' => $transaction->billable_type,
            'billable_id' => $transaction->billable_id,
            'nets_transaction_id' => $transaction->id,
            'nets_charge_id' => 'charge_123',
            'nets_payment_id' => 'pay_123',
            'nets_refund_id' => 'refund_123',
            'idempotency_key' => 'nets-refund-charge_123-a1',
            'status' => Refund::STATUS_PENDING,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_refund_completed'))
            ->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'idempotency_key' => 'nets-refund-charge_123-a1',
            'status' => Refund::STATUS_COMPLETED,
        ]);

        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);
    }

    public function test_a_duplicate_completed_refund_does_not_create_a_second_row(): void
    {
        $transaction = $this->createTransaction();

        $payload = $this->refundPayload('payment.refund.completed', 'evt_refund_completed');

        $this->postJson('/nets/webhook', $payload)->assertOk();
        $this->postJson('/nets/webhook', $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);
    }

    public function test_a_refund_webhook_without_a_local_transaction_is_recorded(): void
    {
        // No charge transaction exists locally (e.g. a refund issued from the
        // Nexi portal). The completed event carries no chargeId, so the charge
        // cannot be recovered; the refund must still be recorded without crashing.
        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_orphan_refund'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'nets_charge_id' => null,
            'billable_type' => null,
            'billable_id' => null,
            'status' => Refund::STATUS_COMPLETED,
        ]);
    }

    public function test_an_out_of_order_initiated_event_does_not_regress_a_completed_refund(): void
    {
        $transaction = $this->createTransaction();

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_completed'))
            ->assertOk();

        // A delayed "initiated" delivery for the same refund arrives afterwards.
        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.initiated', 'evt_initiated_late'))
            ->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_COMPLETED,
        ]);
        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);
    }

    public function test_a_completed_refund_does_not_flip_a_non_succeeded_transaction(): void
    {
        $transaction = $this->createTransaction(['status' => Transaction::STATUS_FAILED]);

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_completed'))
            ->assertOk();

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_COMPLETED,
        ]);
        $this->assertSame(Transaction::STATUS_FAILED, $transaction->fresh()->status);
    }

    public function test_a_completed_refund_does_not_flip_a_transaction_with_an_unknown_amount(): void
    {
        $transaction = $this->createTransaction(['amount' => null]);

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_completed'))
            ->assertOk();

        $this->assertSame(Transaction::STATUS_SUCCEEDED, $transaction->fresh()->status);
    }

    public function test_a_refund_webhook_reconciles_a_timed_out_locally_initiated_refund(): void
    {
        $transaction = $this->createTransaction();

        // A locally-initiated refund whose API response never landed (e.g. a
        // timeout): the row is keyed by its idempotency key with no nets_refund_id.
        $transaction->refunds()->create([
            'billable_type' => $transaction->billable_type,
            'billable_id' => $transaction->billable_id,
            'nets_transaction_id' => $transaction->id,
            'nets_charge_id' => 'charge_123',
            'nets_payment_id' => 'pay_123',
            'idempotency_key' => 'nets-refund-charge_123-a1',
            'status' => Refund::STATUS_PENDING,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_reconcile'))
            ->assertOk();

        // The webhook adopts the existing pending row instead of inserting a duplicate.
        $this->assertSame(1, Refund::query()->count());
        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'idempotency_key' => 'nets-refund-charge_123-a1',
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 9900,
        ]);
        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);
    }

    public function test_a_completed_refund_without_an_amount_keeps_the_locally_known_amount_and_flips(): void
    {
        $transaction = $this->createTransaction();

        $transaction->refunds()->create([
            'billable_type' => $transaction->billable_type,
            'billable_id' => $transaction->billable_id,
            'nets_transaction_id' => $transaction->id,
            'nets_charge_id' => 'charge_123',
            'idempotency_key' => 'nets-refund-charge_123-a1',
            'status' => Refund::STATUS_PENDING,
            'amount' => 9900,
            'currency' => 'DKK',
        ]);

        $payload = $this->refundPayload('payment.refund.completed', 'evt_no_amount');
        unset($payload['data']['amount']);

        $this->postJson('/nets/webhook', $payload)->assertOk();

        // The amount the webhook omitted is preserved from the local row, so the
        // completed refund still covers the charge and flips the transaction.
        $this->assertSame(1, Refund::query()->count());
        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 9900,
        ]);
        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);
    }

    public function test_a_completed_refund_overrides_an_earlier_failed_refund(): void
    {
        $transaction = $this->createTransaction();

        $failed = $this->refundPayload('payment.refund.failed', 'evt_failed_first');
        $failed['data']['error'] = ['code' => 'TEMP', 'message' => 'temporary'];
        $this->postJson('/nets/webhook', $failed)->assertOk();

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_FAILED,
        ]);

        // The same refund later completes; completion must win over the failure.
        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_completed_after'))
            ->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_COMPLETED,
            'failure_code' => null,
            'failure_message' => null,
        ]);
        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);
    }

    public function test_a_late_failed_event_does_not_overwrite_a_completed_refund(): void
    {
        $transaction = $this->createTransaction();

        // The refund completes first (the strongest terminal state).
        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_completed'))
            ->assertOk();

        // A stray, out-of-order "failed" delivery for the same refund arrives with
        // a different amount and an error. It must neither regress the status nor
        // rewrite the completed refund's amount or clear/raise a failure.
        $failed = $this->refundPayload('payment.refund.failed', 'evt_failed_late');
        $failed['data']['amount']['amount'] = '1';
        $failed['data']['error'] = ['code' => 'LATE', 'message' => 'late failure'];
        $this->postJson('/nets/webhook', $failed)->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 9900,
            'failure_code' => null,
            'failure_message' => null,
        ]);
        $this->assertSame(Transaction::STATUS_REFUNDED, $transaction->fresh()->status);
    }

    public function test_a_completed_refund_records_the_reconciliation_reference(): void
    {
        $transaction = $this->createTransaction();

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_completed'))
            ->assertOk();

        $refund = Refund::query()->where('nets_refund_id', 'refund_123')->first();

        $this->assertSame('recon_123', $refund->metadata['reconciliation_reference'] ?? null);
    }

    public function test_a_refund_webhook_does_not_link_to_a_non_succeeded_transaction(): void
    {
        // Only a failed charge transaction exists for this charge id. The refund
        // must not adopt its billable (the markTransaction guard already refuses
        // to flip a non-succeeded row), so the refund is recorded unlinked.
        $this->createTransaction(['status' => Transaction::STATUS_FAILED]);

        $this->postJson('/nets/webhook', $this->refundPayload('payment.refund.completed', 'evt_unlinked'))
            ->assertOk();

        $this->assertDatabaseHas('nets_refunds', [
            'nets_refund_id' => 'refund_123',
            'nets_charge_id' => null,
            'billable_type' => null,
            'billable_id' => null,
            'nets_transaction_id' => null,
            'status' => Refund::STATUS_COMPLETED,
        ]);
    }

    /**
     * Create a succeeded charge transaction for a billable.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createTransaction(array $attributes = []): Transaction
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor'.User::query()->count().'@example.com',
            'password' => 'secret',
        ]);

        return $user->netsTransactions()->create(array_merge([
            'nets_payment_id' => 'pay_123',
            'nets_charge_id' => 'charge_123',
            'nets_subscription_id' => 'sub_123',
            'status' => Transaction::STATUS_SUCCEEDED,
            'amount' => 9900,
            'currency' => 'DKK',
        ], $attributes));
    }

    /**
     * Build a payment.refund.* webhook payload mirroring the live Nexi shapes.
     *
     * Only payment.refund.initiated carries data.chargeId; the completed and
     * failed events omit it and carry data.reconciliationReference instead (per
     * the Nexi payment-webhooks-v1 reference). Tests must rely on this difference.
     *
     * @return array<string, mixed>
     */
    protected function refundPayload(string $event, string $id): array
    {
        $data = [
            'paymentId' => 'pay_123',
            'refundId' => 'refund_123',
            'amount' => [
                'amount' => '9900',
                'currency' => 'DKK',
            ],
        ];

        if ($event === 'payment.refund.initiated') {
            $data['chargeId'] = 'charge_123';
        } else {
            $data['reconciliationReference'] = 'recon_123';
        }

        return [
            'id' => $id,
            'event' => $event,
            'timestamp' => '2026-04-30T05:04:00.4502+00:00',
            'merchantId' => 100242833,
            'merchantNumber' => 0,
            'data' => $data,
        ];
    }
}
