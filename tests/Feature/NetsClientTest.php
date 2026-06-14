<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\NetsException;
use Udviklr\CashierNets\Exceptions\RefundException;

class NetsClientTest extends TestCase
{
    public function test_it_sends_authenticated_api_requests_without_bearer_scheme(): void
    {
        Http::fake([
            CashierNets::apiUrl().'/v1/payments' => Http::response(['paymentId' => 'pay_123'], 200),
        ]);

        $response = CashierNets::api('POST', 'v1/payments', ['order' => ['amount' => 1000]], [
            'idempotency_key' => 'idem_123',
        ]);

        $this->assertSame('pay_123', $response->json('paymentId'));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://test.api.dibspayment.eu/v1/payments'
                && $request->method() === 'POST'
                && $request->header('Authorization') === ['test-secret-key']
                && $request->header('Idempotency-Key') === ['idem_123']
                && $request['order']['amount'] === 1000;
        });
    }

    public function test_it_uses_the_live_base_url_when_sandbox_is_disabled(): void
    {
        config(['cashier-nets.sandbox' => false]);

        Http::fake([
            'https://api.dibspayment.eu/v1/payments/pay_123' => Http::response(['payment' => ['paymentId' => 'pay_123']], 200),
        ]);

        CashierNets::api('GET', 'v1/payments/pay_123');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.dibspayment.eu/v1/payments/pay_123');
    }

    public function test_it_requires_a_secret_key(): void
    {
        config(['cashier-nets.secret_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nets secret key not set.');

        CashierNets::api('GET', 'v1/payments/pay_123');
    }

    public function test_it_throws_a_nets_exception_for_failed_responses(): void
    {
        Http::fake([
            CashierNets::apiUrl().'/v1/payments' => Http::response(['message' => 'Invalid request.'], 400),
        ]);

        $this->expectException(NetsException::class);
        $this->expectExceptionMessage('Invalid request.');

        CashierNets::api('POST', 'v1/payments', []);
    }

    public function test_it_terminates_an_open_payment(): void
    {
        Http::fake([
            CashierNets::apiUrl().'/v1/payments/pay_123/terminate' => Http::response(null, 204),
        ]);

        CashierNets::terminatePayment('pay_123');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://test.api.dibspayment.eu/v1/payments/pay_123/terminate'
                && $request->method() === 'PUT'
                && $request->header('Authorization') === ['test-secret-key'];
        });
    }

    public function test_terminating_a_charged_payment_throws_a_nets_exception(): void
    {
        Http::fake([
            CashierNets::apiUrl().'/v1/payments/pay_123/terminate' => Http::response([
                'message' => 'The payment cannot be terminated.',
            ], 400),
        ]);

        $this->expectException(NetsException::class);
        $this->expectExceptionMessage('The payment cannot be terminated.');

        CashierNets::terminatePayment('pay_123');
    }

    public function test_terminating_a_payment_requires_a_payment_id(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A Nets payment ID is required.');

        try {
            CashierNets::terminatePayment('  ');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_refunds_a_charge_with_an_idempotency_key(): void
    {
        Http::fake([
            CashierNets::apiUrl().'/v1/charges/charge_123/refunds' => Http::response(['refundId' => 'refund_123'], 201),
        ]);

        $response = CashierNets::client()->refundCharge('charge_123', 9900, 'nets-refund-charge_123-a1');

        $this->assertSame('refund_123', $response['refundId']);

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://test.api.dibspayment.eu/v1/charges/charge_123/refunds'
                && $request->method() === 'POST'
                && $request->header('Idempotency-Key') === ['nets-refund-charge_123-a1']
                && $payload['amount'] === 9900
                && ! array_key_exists('orderItems', $payload);
        });
    }

    public function test_it_throws_a_refund_exception_for_failed_refund_responses(): void
    {
        Http::fake([
            CashierNets::apiUrl().'/v1/charges/charge_123/refunds' => Http::response(['message' => 'Charge is not settled.'], 400),
        ]);

        try {
            CashierNets::client()->refundCharge('charge_123', 9900, 'idem');
            $this->fail('Expected a RefundException.');
        } catch (RefundException $exception) {
            $this->assertInstanceOf(NetsException::class, $exception);
            $this->assertSame('Charge is not settled.', $exception->getMessage());
            $this->assertSame(400, $exception->getCode());
            $this->assertSame(['message' => 'Charge is not settled.'], $exception->body());
        }
    }

    public function test_refunding_rejects_a_non_positive_amount(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');

        try {
            CashierNets::client()->refundCharge('charge_123', 0, 'idem');
        } finally {
            Http::assertNothingSent();
        }
    }
}
