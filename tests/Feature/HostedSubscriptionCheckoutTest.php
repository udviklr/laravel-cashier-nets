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
use Udviklr\CashierNets\Exceptions\CheckoutFinalizationException;
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
            ->myReference('INV-2026-000123')
            ->returnUrl('https://example.com/billing/return')
            ->cancelUrl('https://example.com/billing/cancel')
            ->termsUrl('https://example.com/terms')
            ->merchantHandlesConsumerData()
            ->endDate(Carbon::parse('2027-01-01T00:00:00Z'))
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
        $this->assertSame('INV-2026-000123', $checkout->subscription()?->metadata['my_reference']);

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
        $this->assertTrue($payload['checkout']['merchantHandlesConsumerData']);
        $this->assertFalse($payload['checkout']['charge']);
        $this->assertSame(9900, $payload['order']['amount']);
        $this->assertSame('DKK', $payload['order']['currency']);
        $this->assertSame('pro-plan', $payload['order']['items'][0]['reference']);
        $this->assertSame(30, $payload['subscription']['interval']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $payload['subscription']['endDate']);
        $this->assertSame('INV-2026-000123', $payload['myReference']);
        $this->assertSame('https://example.com/nets/webhook', $payload['notifications']['webHooks'][0]['url']);
        $this->assertSame('webhook-secret', $payload['notifications']['webHooks'][0]['authorization']);
    }

    public function test_a_subscription_checkout_requires_an_end_date(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A subscription end date is required.');

        $user->newNetsSubscription()
            ->amount(5000)
            ->returnUrl('https://example.com/return')
            ->checkout();
    }

    public function test_subscription_checkout_rejects_too_long_my_reference_values(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The Nets myReference value may not be greater than 36 characters.');

        $user->newNetsSubscription()
            ->myReference(str_repeat('A', 37));
    }

    public function test_subscription_checkout_accepts_merchant_reference_alias(): void
    {
        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $builder = $user->newNetsSubscription()
            ->merchantReference('INV-2026-000123');

        $property = new \ReflectionProperty($builder, 'myReference');

        $this->assertSame('INV-2026-000123', $property->getValue($builder));
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

    public function test_subscription_checkout_accepts_custom_order_items(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/payments' => Http::response([
                'paymentId' => 'pay_taxed',
            ]),
        ]);

        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        // 5,000.00 DKK gross at 25% VAT = 4,000.00 net + 1,000.00 VAT. Per the Nets spec,
        // unitPrice excludes VAT, so it is the net figure (4000), not the gross (5000).
        $checkout = $user->newNetsSubscription()
            ->amount(5000)
            ->currency('DKK')
            ->returnUrl('https://example.com/return')
            ->endDate(Carbon::parse('2027-01-01T00:00:00Z'))
            ->orderItems([[
                'reference' => 'business-yearly',
                'name' => 'Business - Yearly',
                'quantity' => 1,
                'unit' => 'pcs',
                'unitPrice' => 4000,
                'taxRate' => 2500,
                'taxAmount' => 1000,
                'grossTotalAmount' => 5000,
                'netTotalAmount' => 4000,
            ]])
            ->checkout();

        $recorded = Http::recorded();
        $payload = json_decode($recorded[0][0]->body(), true);
        $item = $payload['order']['items'][0];

        $this->assertSame(4000, $item['unitPrice']);
        $this->assertSame(2500, $item['taxRate']);
        $this->assertSame(1000, $item['taxAmount']);
        $this->assertSame(4000, $item['netTotalAmount']);
        $this->assertSame(5000, $item['grossTotalAmount']);

        // The item must satisfy the exact Nets invariants the package now enforces.
        $this->assertSame($item['unitPrice'] * $item['quantity'], $item['netTotalAmount']);
        $this->assertSame($item['netTotalAmount'] + $item['taxAmount'], $item['grossTotalAmount']);
        $this->assertSame($payload['order']['amount'], $item['grossTotalAmount']);

        $this->assertSame($payload['order']['items'], $checkout->subscription()?->metadata['order_items']);
    }

    public function test_subscription_checkout_rejects_inconsistent_order_items(): void
    {
        Http::fake([
            'https://test.api.dibspayment.eu/v1/payments' => Http::response([
                'paymentId' => 'pay_taxed',
            ]),
        ]);

        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        // unitPrice is the gross amount here, so netTotalAmount (4000) != unitPrice * quantity (5000).
        // This is the exact mistake that Nets would otherwise reject server-side.
        $builder = $user->newNetsSubscription()
            ->amount(5000)
            ->currency('DKK')
            ->returnUrl('https://example.com/return')
            ->endDate(Carbon::parse('2027-01-01T00:00:00Z'))
            ->orderItems([[
                'reference' => 'business-yearly',
                'name' => 'Business - Yearly',
                'quantity' => 1,
                'unit' => 'pcs',
                'unitPrice' => 5000,
                'taxRate' => 2500,
                'taxAmount' => 1000,
                'grossTotalAmount' => 5000,
                'netTotalAmount' => 4000,
            ]]);

        try {
            $builder->checkout();
            $this->fail('Expected an InvalidArgumentException for inconsistent order items.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unitPrice', $e->getMessage());
        }

        // Validation happens before any API call or local persistence.
        Http::assertNothingSent();
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

    public function test_a_billable_model_can_finalize_a_checkout_subscription_from_a_payment_id(): void
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

        $subscription = $user->syncNetsSubscriptionFromPayment('pay_123', [
            'amount' => 9900,
            'currency' => 'DKK',
            'interval_days' => 30,
        ], 'main');

        $this->assertSame('pay_123', $subscription->nets_payment_id);
        $this->assertSame('sub_123', $subscription->nets_subscription_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(9900, $subscription->amount);
        $this->assertSame('DKK', $subscription->currency);
        $this->assertSame(30, $subscription->interval_days);

        $again = $user->syncNetsSubscriptionFromPayment('pay_123', type: 'main');

        $this->assertTrue($subscription->is($again));
        $this->assertDatabaseCount('nets_subscriptions', 1);
    }

    public function test_checkout_finalization_fails_when_nets_does_not_return_a_subscription_id(): void
    {
        CashierNets::fake([
            'v1/payments/pay_123' => [
                'payment' => [
                    'paymentId' => 'pay_123',
                ],
            ],
        ]);

        $user = User::create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'password' => 'secret',
        ]);

        $this->expectException(CheckoutFinalizationException::class);
        $this->expectExceptionMessage('The Nets payment did not return a subscription ID.');

        $user->syncNetsSubscriptionFromPayment('pay_123');
    }
}
