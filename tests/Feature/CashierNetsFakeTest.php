<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\CashierNetsFake;
use Udviklr\CashierNets\Events\WebhookHandled;
use Udviklr\CashierNets\Events\WebhookReceived;
use Udviklr\CashierNets\Exceptions\NetsException;

class CashierNetsFakeTest extends TestCase
{
    public function test_it_can_fake_an_api_endpoint_response(): void
    {
        CashierNets::fake([
            'v1/payments' => ['paymentId' => 'pay_123'],
        ]);

        $response = CashierNets::api('POST', 'v1/payments', [
            'order' => ['amount' => 1000],
        ]);

        $this->assertSame('pay_123', $response->json('paymentId'));
    }

    public function test_it_can_overwrite_a_fake_api_response(): void
    {
        CashierNets::fake()
            ->response('v1/payments/pay_123', ['payment' => ['paymentId' => 'pay_123']])
            ->response('v1/payments/pay_123', ['payment' => ['paymentId' => 'pay_456']]);

        $response = CashierNets::api('GET', 'v1/payments/pay_123');

        $this->assertSame('pay_456', $response->json('payment.paymentId'));
    }

    public function test_it_can_fake_an_api_error(): void
    {
        CashierNets::fake()->error('v1/payments', 'Validation failed.', 422);

        $this->expectException(NetsException::class);
        $this->expectExceptionMessage('Validation failed.');

        CashierNets::api('POST', 'v1/payments', []);
    }

    public function test_it_formats_fake_api_urls(): void
    {
        $this->assertSame(
            'https://test.api.dibspayment.eu/v1/payments',
            CashierNetsFake::getFormattedApiUrl('v1/payments'),
        );
    }

    public function test_it_fakes_and_asserts_package_events(): void
    {
        CashierNets::fake();

        event(new WebhookReceived(['eventName' => 'payment.created']));
        event(new WebhookHandled(['eventName' => 'payment.created']));

        CashierNets::assertWebhookReceived(function (WebhookReceived $event) {
            return $event->payload['eventName'] === 'payment.created';
        });

        CashierNets::assertWebhookHandled(function (WebhookHandled $event) {
            return $event->payload['eventName'] === 'payment.created';
        });

        Event::assertDispatched(WebhookReceived::class, 1);
        Event::assertDispatched(WebhookHandled::class, 1);
    }
}
