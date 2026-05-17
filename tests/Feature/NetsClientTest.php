<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\NetsException;

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
}
