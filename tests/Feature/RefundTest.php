<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use RuntimeException;
use Tests\TestCase;
use Udviklr\CashierNets\Exceptions\RefundException;
use Udviklr\CashierNets\Refund;
use Udviklr\CashierNets\Transaction;
use Workbench\App\Models\User;

class RefundTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_a_full_refund_sends_the_charge_amount_without_order_items(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::response([
                'refundId' => 'refund_123',
            ]),
        ]);

        $transaction = $this->createTransaction();

        $refund = $transaction->refund();

        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertSame('refund_123', $refund->fresh()->nets_refund_id);
        $this->assertSame(9900, $refund->amount);
        $this->assertSame('DKK', $refund->currency);
        $this->assertSame($transaction->id, (int) $refund->nets_transaction_id);
        $this->assertSame('nets-refund-charge_123-a1', $refund->idempotency_key);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === 'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds'
                && $request->header('Idempotency-Key') === ['nets-refund-charge_123-a1']
                && $payload['amount'] === 9900
                && ! array_key_exists('orderItems', $payload);
        });

        Http::assertSentCount(1);
    }

    public function test_a_partial_refund_synthesizes_a_zero_tax_order_item(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::response([
                'refundId' => 'refund_partial',
            ]),
        ]);

        $transaction = $this->createTransaction();

        $refund = $transaction->refund(2500);

        $this->assertSame(2500, $refund->amount);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);
            $item = $payload['orderItems'][0] ?? null;

            return $payload['amount'] === 2500
                && $item !== null
                && $item['unitPrice'] === 2500
                && $item['netTotalAmount'] === 2500
                && $item['taxAmount'] === 0
                && $item['grossTotalAmount'] === 2500;
        });
    }

    public function test_a_partial_refund_accepts_custom_vat_aware_order_items(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::response([
                'refundId' => 'refund_vat',
            ]),
        ]);

        $transaction = $this->createTransaction();

        $transaction->refund(5000, [[
            'reference' => 'business-yearly',
            'name' => 'Business - Yearly',
            'quantity' => 1,
            'unit' => 'pcs',
            'unitPrice' => 4000,
            'taxRate' => 2500,
            'taxAmount' => 1000,
            'grossTotalAmount' => 5000,
            'netTotalAmount' => 4000,
        ]]);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);
            $item = $payload['orderItems'][0];

            return $payload['amount'] === 5000
                && $item['unitPrice'] === 4000
                && $item['taxAmount'] === 1000
                && $item['grossTotalAmount'] === 5000;
        });
    }

    public function test_it_rejects_inconsistent_custom_order_items(): void
    {
        Http::fake();

        $transaction = $this->createTransaction();

        try {
            // unitPrice (5000) does not match netTotalAmount (4000) for quantity 1.
            $transaction->refund(5000, [[
                'reference' => 'x',
                'name' => 'X',
                'quantity' => 1,
                'unit' => 'pcs',
                'unitPrice' => 5000,
                'taxRate' => 2500,
                'taxAmount' => 1000,
                'grossTotalAmount' => 5000,
                'netTotalAmount' => 4000,
            ]]);
            $this->fail('Expected an InvalidArgumentException for inconsistent order items.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unitPrice', $e->getMessage());
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, Refund::query()->count());
        }
    }

    public function test_it_rejects_a_non_positive_amount(): void
    {
        Http::fake();

        $transaction = $this->createTransaction();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $transaction->refund(0);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_rejects_an_amount_greater_than_the_remaining_refundable(): void
    {
        Http::fake();

        $transaction = $this->createTransaction();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the remaining refundable amount');

        try {
            $transaction->refund(10000);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_multiple_partial_refunds_track_the_remaining_refundable_amount(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::sequence()
                ->push(['refundId' => 'refund_a'])
                ->push(['refundId' => 'refund_b']),
        ]);

        $transaction = $this->createTransaction();

        $transaction->refund(6000);
        $this->assertSame(3900, $transaction->remainingRefundable());

        $transaction->refund(3900);
        $this->assertSame(0, $transaction->remainingRefundable());

        $this->assertSame(2, Refund::query()->count());

        $keys = [];

        Http::assertSent(function (Request $request) use (&$keys): bool {
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return true;
        });

        $this->assertSame(['nets-refund-charge_123-a1', 'nets-refund-charge_123-a2'], $keys);
    }

    public function test_it_only_refunds_succeeded_transactions(): void
    {
        Http::fake();

        $transaction = $this->createTransaction(['status' => Transaction::STATUS_FAILED]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only succeeded transactions can be refunded.');

        try {
            $transaction->refund();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_requires_a_charge_id(): void
    {
        Http::fake();

        $transaction = $this->createTransaction(['nets_charge_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not have a Nets charge ID');

        try {
            $transaction->refund();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_failed_refund_api_call_marks_the_refund_failed_and_throws(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::response([
                'message' => 'Charge is not settled.',
            ], 400),
        ]);

        $transaction = $this->createTransaction();

        try {
            $transaction->refund();
            $this->fail('Expected a RefundException.');
        } catch (RefundException $e) {
            $this->assertSame('Charge is not settled.', $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }

        $this->assertDatabaseHas('nets_refunds', [
            'nets_charge_id' => 'charge_123',
            'status' => Refund::STATUS_FAILED,
            'failure_code' => '400',
            'failure_message' => 'Charge is not settled.',
        ]);
    }

    public function test_a_connection_failure_leaves_the_refund_pending_and_reserves_the_amount(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('Connection timed out');
        });

        $transaction = $this->createTransaction();

        try {
            $transaction->refund();
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException) {
            // The refund outcome is unknown, so the attempt is left pending.
        }

        // The pending row still reserves the full amount, so a naive retry is
        // rejected rather than issuing a second refund for the same charge.
        $this->assertDatabaseHas('nets_refunds', [
            'idempotency_key' => 'nets-refund-charge_123-a1',
            'status' => Refund::STATUS_PENDING,
            'amount' => 9900,
        ]);
        $this->assertSame(0, $transaction->remainingRefundable());

        try {
            $transaction->refund();
            $this->fail('Expected the naive retry to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds the remaining refundable amount', $e->getMessage());
        }
    }

    public function test_retrying_a_pending_refund_with_the_same_idempotency_key_reconciles_it(): void
    {
        $transaction = $this->createTransaction();

        // Simulate a prior attempt that was left pending after an unknown outcome.
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

        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::response(['refundId' => 'refund_123']),
        ]);

        $refund = $transaction->refund(null, [], 'nets-refund-charge_123-a1');

        // No second row is created and the same idempotency key is sent to Nets.
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame('refund_123', $refund->fresh()->nets_refund_id);

        Http::assertSent(fn (Request $request): bool => $request->header('Idempotency-Key') === ['nets-refund-charge_123-a1']);
    }

    public function test_a_webhook_only_refund_with_a_null_idempotency_key_counts_toward_the_cap(): void
    {
        Http::fake();

        $transaction = $this->createTransaction();

        // A refund recorded only via webhook (e.g. issued from the Nexi portal)
        // has a null idempotency_key but must still reserve part of the balance.
        Refund::query()->create([
            'billable_type' => $transaction->billable_type,
            'billable_id' => $transaction->billable_id,
            'nets_charge_id' => 'charge_123',
            'nets_refund_id' => 'refund_portal',
            'idempotency_key' => null,
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 5000,
            'currency' => 'DKK',
        ]);

        $this->assertSame(4900, $transaction->remainingRefundable());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the remaining refundable amount');

        try {
            $transaction->refund(5000);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_reusing_a_completed_refund_idempotency_key_returns_the_existing_refund(): void
    {
        Http::fake();

        $transaction = $this->createTransaction();

        $existing = $transaction->refunds()->create([
            'billable_type' => $transaction->billable_type,
            'billable_id' => $transaction->billable_id,
            'nets_transaction_id' => $transaction->id,
            'nets_charge_id' => 'charge_123',
            'nets_refund_id' => 'refund_done',
            'idempotency_key' => 'reused-key',
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 2500,
            'currency' => 'DKK',
        ]);

        $refund = $transaction->refund(2500, [], 'reused-key');

        $this->assertTrue($refund->is($existing));
        $this->assertSame(1, Refund::query()->count());
        Http::assertNothingSent();
    }

    public function test_reusing_a_completed_idempotency_key_for_a_different_amount_is_rejected(): void
    {
        Http::fake();

        $transaction = $this->createTransaction();

        $transaction->refunds()->create([
            'billable_type' => $transaction->billable_type,
            'billable_id' => $transaction->billable_id,
            'nets_transaction_id' => $transaction->id,
            'nets_charge_id' => 'charge_123',
            'nets_refund_id' => 'refund_done',
            'idempotency_key' => 'reused-key',
            'status' => Refund::STATUS_COMPLETED,
            'amount' => 2500,
            'currency' => 'DKK',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be reused for a different amount');

        try {
            $transaction->refund(3000, [], 'reused-key');
        } finally {
            Http::assertNothingSent();
            $this->assertSame(1, Refund::query()->count());
        }
    }

    public function test_reusing_a_pending_idempotency_key_for_a_different_amount_is_rejected(): void
    {
        Http::fake();

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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry with the same amount');

        try {
            $transaction->refund(2500, [], 'nets-refund-charge_123-a1');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_reusing_a_failed_refund_idempotency_key_is_rejected(): void
    {
        Http::fake();

        $transaction = $this->createTransaction();

        $transaction->refunds()->create([
            'billable_type' => $transaction->billable_type,
            'billable_id' => $transaction->billable_id,
            'nets_transaction_id' => $transaction->id,
            'nets_charge_id' => 'charge_123',
            'idempotency_key' => 'failed-key',
            'status' => Refund::STATUS_FAILED,
            'amount' => 2500,
            'currency' => 'DKK',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A failed refund is already recorded');

        try {
            $transaction->refund(2500, [], 'failed-key');
        } finally {
            Http::assertNothingSent();
            $this->assertSame(Refund::STATUS_FAILED, Refund::query()->first()->status);
        }
    }

    public function test_a_full_refund_does_not_send_order_items_even_when_supplied(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::response([
                'refundId' => 'refund_full',
            ]),
        ]);

        $transaction = $this->createTransaction();

        // Order items supplied for a full refund are ignored: the whole charge is
        // refunded with no line spec, matching the documented contract.
        $transaction->refund(9900, [[
            'reference' => 'business-yearly',
            'name' => 'Business - Yearly',
            'quantity' => 1,
            'unit' => 'pcs',
            'unitPrice' => 7920,
            'taxRate' => 2500,
            'taxAmount' => 1980,
            'grossTotalAmount' => 9900,
            'netTotalAmount' => 7920,
        ]]);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $payload['amount'] === 9900
                && ! array_key_exists('orderItems', $payload);
        });
    }

    public function test_a_failed_refund_records_the_nets_error_code_from_the_body(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds' => Http::response([
                'error' => ['code' => 'REFUND_REJECTED', 'message' => 'Refund was rejected.'],
            ], 400),
        ]);

        $transaction = $this->createTransaction();

        try {
            $transaction->refund();
            $this->fail('Expected a RefundException.');
        } catch (RefundException $e) {
            $this->assertSame(400, $e->getCode());
        }

        // failure_code records the Nets domain code (same code space as the
        // refund webhook), not the HTTP status, when the body carries one.
        $this->assertDatabaseHas('nets_refunds', [
            'nets_charge_id' => 'charge_123',
            'status' => Refund::STATUS_FAILED,
            'failure_code' => 'REFUND_REJECTED',
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
}
