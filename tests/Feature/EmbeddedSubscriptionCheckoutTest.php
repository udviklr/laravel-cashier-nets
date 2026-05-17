<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use RuntimeException;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Checkout;
use Udviklr\CashierNets\Subscription;
use Workbench\App\Models\User;

class EmbeddedSubscriptionCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_a_billable_model_can_create_an_embedded_subscription_checkout(): void
    {
        config(['app.url' => 'https://example.com']);
        config(['cashier-nets.checkout_key' => 'checkout-key-123']);
        URL::forceRootUrl('https://example.com');
        URL::forceScheme('https');

        Http::fake([
            'https://test.api.dibspayment.eu/v1/payments' => Http::response([
                'paymentId' => 'pay_embedded_123',
            ]),
        ]);

        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $checkout = $user->newNetsSubscription('main')
            ->amount(9900)
            ->currency('DKK')
            ->intervalDays(30)
            ->description('Pro plan')
            ->reference('pro-plan')
            ->checkoutUrl('https://example.com/billing/checkout')
            ->termsUrl('https://example.com/terms')
            ->metadata(['plan' => 'pro'])
            ->embeddedCheckout();

        $this->assertInstanceOf(Checkout::class, $checkout);
        $this->assertSame('pay_embedded_123', $checkout->paymentId());
        $this->assertNull($checkout->url());
        $this->assertSame('pay_embedded_123', $checkout->subscription()?->nets_payment_id);
        $this->assertSame('checkout-key-123', CashierNets::checkoutKey());
        $this->assertSame('https://test.checkout.dibspayment.eu/v1/checkout.js?v=1', CashierNets::checkoutJsUrl());

        $this->assertDatabaseHas('nets_subscriptions', [
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => 'main',
            'nets_payment_id' => 'pay_embedded_123',
            'status' => Subscription::STATUS_PENDING,
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
        ]);

        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        $request = $recorded[0][0];
        $payload = json_decode($request->body(), true);

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://test.api.dibspayment.eu/v1/payments', $request->url());
        $this->assertSame('EmbeddedCheckout', $payload['checkout']['integrationType']);
        $this->assertSame('https://example.com/billing/checkout', $payload['checkout']['url']);
        $this->assertSame('https://example.com/terms', $payload['checkout']['termsUrl']);
        $this->assertArrayNotHasKey('returnUrl', $payload['checkout']);
        $this->assertArrayNotHasKey('cancelUrl', $payload['checkout']);
        $this->assertSame(9900, $payload['order']['amount']);
        $this->assertSame('DKK', $payload['order']['currency']);
        $this->assertSame(30, $payload['subscription']['interval']);
    }

    public function test_an_embedded_checkout_requires_a_checkout_url(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A checkout URL is required.');

        $user->newNetsSubscription()
            ->amount(9900)
            ->embeddedCheckout();
    }

    public function test_embedded_checkout_cannot_be_redirected(): void
    {
        $checkout = new Checkout('pay_123', null, ['paymentId' => 'pay_123']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This checkout does not have a hosted checkout URL.');

        $checkout->redirect();
    }
}
