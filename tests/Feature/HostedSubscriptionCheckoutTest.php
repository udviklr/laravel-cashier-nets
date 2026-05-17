<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Checkout;
use Udviklr\CashierNets\Subscription;
use Workbench\App\Models\User;

class HostedSubscriptionCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_a_billable_model_can_create_a_hosted_subscription_checkout(): void
    {
        config(['app.url' => 'https://example.com']);
        config(['cashier-nets.webhook_authorization' => 'webhook-secret']);
        URL::forceRootUrl('https://example.com');
        URL::forceScheme('https');

        Http::fake([
            'https://test.api.dibspayment.eu/v1/payments' => Http::response([
                'paymentId' => 'pay_123',
                'hostedPaymentPageUrl' => 'https://test.checkout.dibspayment.eu/hostedpaymentpage/?checkoutKey=abc',
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
            ->returnUrl('https://example.com/billing/return')
            ->cancelUrl('https://example.com/billing/cancel')
            ->termsUrl('https://example.com/terms')
            ->metadata(['plan' => 'pro'])
            ->checkout();

        $this->assertInstanceOf(Checkout::class, $checkout);
        $this->assertSame('pay_123', $checkout->paymentId());
        $this->assertSame('https://test.checkout.dibspayment.eu/hostedpaymentpage/?checkoutKey=abc', $checkout->url());
        $this->assertSame('pay_123', $checkout->subscription()?->nets_payment_id);

        $this->assertDatabaseHas('nets_subscriptions', [
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
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
        $this->assertSame('HostedPaymentPage', $payload['checkout']['integrationType']);
        $this->assertSame('https://example.com/billing/return', $payload['checkout']['returnUrl']);
        $this->assertSame('https://example.com/billing/cancel', $payload['checkout']['cancelUrl']);
        $this->assertSame('https://example.com/terms', $payload['checkout']['termsUrl']);
        $this->assertFalse($payload['checkout']['charge']);
        $this->assertSame(9900, $payload['order']['amount']);
        $this->assertSame('DKK', $payload['order']['currency']);
        $this->assertSame('pro-plan', $payload['order']['items'][0]['reference']);
        $this->assertSame(30, $payload['subscription']['interval']);
        $this->assertSame('https://example.com/nets/webhook', $payload['notifications']['webHooks'][0]['url']);
        $this->assertSame('webhook-secret', $payload['notifications']['webHooks'][0]['authorization']);
    }

    public function test_subscription_checkout_can_request_an_initial_charge_and_end_date(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/payments' => Http::response([
                'paymentId' => 'pay_123',
            ]),
        ]);

        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $user->newNetsSubscription()
            ->amount(5000)
            ->currency('EUR')
            ->intervalDays(0)
            ->returnUrl('https://example.com/return')
            ->endDate(Carbon::parse('2027-01-01T00:00:00Z'))
            ->chargeImmediately()
            ->checkout();

        $recorded = Http::recorded();
        $payload = json_decode($recorded[0][0]->body(), true);

        $this->assertTrue($payload['checkout']['charge']);
        $this->assertSame(0, $payload['subscription']['interval']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $payload['subscription']['endDate']);
    }

    public function test_a_subscription_can_sync_provider_ids_from_nets_payment_details(): void
    {
        CashierNets::fake([
            'v1/payments/pay_123' => [
                'payment' => [
                    'paymentId' => 'pay_123',
                    'subscription' => [
                        'id' => 'sub_123',
                    ],
                ],
            ],
        ]);

        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $subscription = $user->netsSubscriptions()->create([
            'type' => 'main',
            'nets_payment_id' => 'pay_123',
            'status' => Subscription::STATUS_PENDING,
        ]);

        $subscription->syncFromNets();

        $this->assertSame('sub_123', $subscription->fresh()->nets_subscription_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }
}
