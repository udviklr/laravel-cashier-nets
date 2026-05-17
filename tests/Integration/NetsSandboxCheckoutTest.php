<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\NetsException;
use Udviklr\CashierNets\Subscription;
use Udviklr\CashierNets\Transaction;
use Workbench\App\Models\User;

#[Group('integration')]
class NetsSandboxCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_it_can_create_a_hosted_subscription_checkout_in_the_nets_sandbox(): void
    {
        $this->configureNetsSandbox();

        $user = $this->createBillableUser();
        $reference = $this->testReference('hosted');
        $myReference = $this->testMerchantReference('hosted');

        $checkout = $user->newNetsSubscription('integration-hosted')
            ->amount($this->testAmount())
            ->currency($this->testCurrency())
            ->intervalDays(30)
            ->description('Cashier Nets sandbox hosted subscription')
            ->reference($reference)
            ->myReference($myReference)
            ->returnUrl($this->urlFromEnv('NETS_TEST_RETURN_URL', 'https://example.com/billing/return'))
            ->cancelUrl($this->urlFromEnv('NETS_TEST_CANCEL_URL', 'https://example.com/billing/cancel'))
            ->termsUrl($this->urlFromEnv('NETS_TEST_TERMS_URL', 'https://example.com/terms'))
            ->endDate($this->testEndDate())
            ->checkout();

        $this->assertNotSame('', $checkout->paymentId());
        $this->assertNotNull($checkout->url());
        $this->assertNotSame('', $checkout->url());

        $this->assertDatabaseHas('nets_subscriptions', [
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => 'integration-hosted',
            'nets_payment_id' => $checkout->paymentId(),
            'status' => Subscription::STATUS_PENDING,
            'amount' => $this->testAmount(),
            'currency' => $this->testCurrency(),
        ]);
        $this->assertSame($myReference, $checkout->subscription()?->metadata['my_reference']);

        $payment = CashierNets::api('GET', 'v1/payments/'.$checkout->paymentId())->json();

        $this->assertSame($checkout->paymentId(), data_get($payment, 'payment.paymentId', data_get($payment, 'paymentId')));
        $this->assertRetrievedPaymentMatchesCheckout($payment, $checkout->paymentId(), $reference);
        $this->assertRetrievedPaymentMyReference($payment, $myReference);
        $this->assertPaymentCheckoutUrl($payment, $checkout->url());
    }

    public function test_it_can_create_an_embedded_subscription_checkout_in_the_nets_sandbox(): void
    {
        $this->configureNetsSandbox(requireCheckoutKey: true);

        $user = $this->createBillableUser();
        $reference = $this->testReference('embedded');
        $myReference = $this->testMerchantReference('embedded');

        $checkout = $user->newNetsSubscription('integration-embedded')
            ->amount($this->testAmount())
            ->currency($this->testCurrency())
            ->intervalDays(30)
            ->description('Cashier Nets sandbox embedded subscription')
            ->reference($reference)
            ->merchantReference($myReference)
            ->checkoutUrl($this->urlFromEnv('NETS_TEST_CHECKOUT_URL', 'https://example.com/billing/checkout'))
            ->termsUrl($this->urlFromEnv('NETS_TEST_TERMS_URL', 'https://example.com/terms'))
            ->endDate($this->testEndDate())
            ->embeddedCheckout();

        $this->assertNotSame('', $checkout->paymentId());
        $this->assertNull($checkout->url());
        $this->assertNotNull(CashierNets::checkoutKey());

        $this->assertDatabaseHas('nets_subscriptions', [
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => 'integration-embedded',
            'nets_payment_id' => $checkout->paymentId(),
            'status' => Subscription::STATUS_PENDING,
            'amount' => $this->testAmount(),
            'currency' => $this->testCurrency(),
        ]);

        $payment = CashierNets::api('GET', 'v1/payments/'.$checkout->paymentId())->json();

        $this->assertSame($checkout->paymentId(), data_get($payment, 'payment.paymentId', data_get($payment, 'paymentId')));
        $this->assertRetrievedPaymentMatchesCheckout($payment, $checkout->paymentId(), $reference);
        $this->assertRetrievedPaymentMyReference($payment, $myReference);
        $this->assertPaymentCheckoutUrl($payment, $this->urlFromEnv('NETS_TEST_CHECKOUT_URL', 'https://example.com/billing/checkout'));
    }

    public function test_it_can_create_a_checkout_with_webhook_notifications_in_the_nets_sandbox(): void
    {
        $this->configureNetsSandbox(enableWebhooks: true);

        $user = $this->createBillableUser();
        $reference = $this->testReference('webhooks');

        $checkout = $user->newNetsSubscription('integration-webhooks')
            ->amount($this->testAmount())
            ->currency($this->testCurrency())
            ->intervalDays(30)
            ->description('Cashier Nets sandbox webhook subscription')
            ->reference($reference)
            ->returnUrl($this->urlFromEnv('NETS_TEST_RETURN_URL', 'https://example.com/billing/return'))
            ->termsUrl($this->urlFromEnv('NETS_TEST_TERMS_URL', 'https://example.com/terms'))
            ->endDate($this->testEndDate())
            ->checkout();

        $payment = CashierNets::api('GET', 'v1/payments/'.$checkout->paymentId())->json();

        $this->assertRetrievedPaymentMatchesCheckout($payment, $checkout->paymentId(), $reference);
        $this->assertDatabaseHas('nets_subscriptions', [
            'type' => 'integration-webhooks',
            'nets_payment_id' => $checkout->paymentId(),
            'status' => Subscription::STATUS_PENDING,
        ]);
    }

    public function test_it_can_create_a_checkout_that_requests_an_initial_charge_in_the_nets_sandbox(): void
    {
        $this->configureNetsSandbox();

        $user = $this->createBillableUser();
        $reference = $this->testReference('initial-charge');

        $checkout = $user->newNetsSubscription('integration-initial-charge')
            ->amount($this->testAmount())
            ->currency($this->testCurrency())
            ->intervalDays(30)
            ->description('Cashier Nets sandbox initial charge subscription')
            ->reference($reference)
            ->returnUrl($this->urlFromEnv('NETS_TEST_RETURN_URL', 'https://example.com/billing/return'))
            ->termsUrl($this->urlFromEnv('NETS_TEST_TERMS_URL', 'https://example.com/terms'))
            ->endDate($this->testEndDate())
            ->chargeImmediately()
            ->checkout();

        $payment = CashierNets::api('GET', 'v1/payments/'.$checkout->paymentId())->json();

        $this->assertRetrievedPaymentMatchesCheckout($payment, $checkout->paymentId(), $reference);
        $this->assertDatabaseHas('nets_subscriptions', [
            'type' => 'integration-initial-charge',
            'nets_payment_id' => $checkout->paymentId(),
            'status' => Subscription::STATUS_PENDING,
        ]);
    }

    public function test_it_receives_useful_exceptions_for_nets_error_responses(): void
    {
        $this->configureNetsSandbox();

        try {
            CashierNets::api('GET', 'v1/payments/'.Str::uuid());

            $this->fail('The Nets API request should have failed for an unknown payment ID.');
        } catch (NetsException $exception) {
            $this->assertGreaterThanOrEqual(400, $exception->getCode());
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_failed_live_subscription_charges_are_recorded_locally(): void
    {
        $this->configureNetsSandbox();

        $user = $this->createBillableUser();
        $idempotencyKey = 'cashier-nets-invalid-charge-'.Str::uuid();
        $subscriptionId = (string) Str::uuid();

        $subscription = $user->netsSubscriptions()->create([
            'type' => 'integration-invalid-charge',
            'nets_subscription_id' => $subscriptionId,
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => $this->testAmount(),
            'currency' => $this->testCurrency(),
            'interval_days' => 30,
            'next_charge_at' => Carbon::now()->subMinute(),
        ]);

        try {
            $subscription->charge([
                'description' => 'Cashier Nets sandbox invalid subscription charge',
                'reference' => $this->testReference('invalid-charge'),
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->fail('The Nets API request should have failed for an unknown subscription ID.');
        } catch (NetsException $exception) {
            $this->assertGreaterThanOrEqual(400, $exception->getCode());
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->failed_at);
        $this->assertDatabaseHas('nets_transactions', [
            'idempotency_key' => $idempotencyKey,
            'nets_subscription_id' => $subscriptionId,
            'status' => Transaction::STATUS_FAILED,
            'amount' => $this->testAmount(),
            'currency' => $this->testCurrency(),
        ]);
    }

    #[Group('nets-charge')]
    public function test_it_can_charge_an_existing_subscription_in_the_nets_sandbox(): void
    {
        $this->configureNetsSandbox();

        $netsSubscriptionId = $this->env('NETS_TEST_SUBSCRIPTION_ID');

        if ($netsSubscriptionId === null) {
            $this->markTestSkipped('Set NETS_TEST_SUBSCRIPTION_ID to run the live Nets subscription charge test.');
        }

        $user = $this->createBillableUser();
        $idempotencyKey = 'cashier-nets-charge-'.Str::uuid();
        $reference = $this->testReference('charge');
        $myReference = $this->testMerchantReference('charge');

        $subscription = $user->netsSubscriptions()->create([
            'type' => 'integration-charge',
            'nets_subscription_id' => $netsSubscriptionId,
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => $this->testAmount(),
            'currency' => $this->testCurrency(),
            'interval_days' => 30,
            'next_charge_at' => Carbon::now()->subMinute(),
        ]);

        $transaction = $subscription->charge([
            'description' => 'Cashier Nets sandbox subscription charge',
            'reference' => $reference,
            'my_reference' => $myReference,
            'idempotency_key' => $idempotencyKey,
        ]);

        $transaction->refresh();

        $this->assertSame(Transaction::STATUS_PENDING, $transaction->status);
        $this->assertSame($idempotencyKey, $transaction->idempotency_key);
        $this->assertSame($netsSubscriptionId, $transaction->nets_subscription_id);
        $this->assertSame($this->testAmount(), $transaction->amount);
        $this->assertSame($this->testCurrency(), $transaction->currency);
        $this->assertNotSame('', (string) $transaction->nets_payment_id);
        $this->assertSame($myReference, $transaction->metadata['my_reference']);

        $payment = CashierNets::api('GET', 'v1/payments/'.$transaction->nets_payment_id)->json();

        $this->assertSame($transaction->nets_payment_id, data_get($payment, 'payment.paymentId', data_get($payment, 'paymentId')));
        $this->assertSame($this->testAmount(), (int) data_get($payment, 'payment.orderDetails.amount'));
        $this->assertSame($this->testCurrency(), data_get($payment, 'payment.orderDetails.currency'));
        $this->assertSame($reference, data_get($payment, 'payment.orderDetails.reference'));
        $this->assertRetrievedPaymentMyReference($payment, $myReference);

        $invoiceNumber = $this->paymentInvoiceNumber($payment);

        if ($invoiceNumber !== null) {
            $this->assertSame($invoiceNumber, $transaction->metadata['invoice_number'] ?? null);
        }
    }

    protected function configureNetsSandbox(bool $requireCheckoutKey = false, bool $enableWebhooks = false): void
    {
        if (! filter_var(getenv('NETS_INTEGRATION') ?: false, FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set NETS_INTEGRATION=true to run Nets sandbox integration tests.');
        }

        $secretKey = $this->env('NETS_SECRET_KEY');

        if ($secretKey === null || $secretKey === 'test-secret-key') {
            $this->markTestSkipped('Set NETS_SECRET_KEY to a real Nets sandbox secret key.');
        }

        $checkoutKey = $this->env('NETS_CHECKOUT_KEY');

        if ($requireCheckoutKey && ($checkoutKey === null || $checkoutKey === 'test-checkout-key')) {
            $this->markTestSkipped('Set NETS_CHECKOUT_KEY to a real Nets sandbox checkout key.');
        }

        config([
            'app.url' => 'https://example.com',
            'cashier-nets.secret_key' => $secretKey,
            'cashier-nets.checkout_key' => $checkoutKey,
            'cashier-nets.sandbox' => true,
            'cashier-nets.webhook_authorization' => $enableWebhooks ? $this->webhookAuthorization() : null,
            'cashier-nets.webhook_events' => $enableWebhooks ? $this->webhookEvents() : [],
        ]);

        URL::forceRootUrl('https://example.com');
        URL::forceScheme('https');
    }

    protected function createBillableUser(): User
    {
        return User::create([
            'name' => 'Nets Sandbox Tester',
            'email' => 'nets-sandbox-'.Str::uuid().'@example.com',
            'password' => 'secret',
        ]);
    }

    /**
     * Get the webhook events to send with live Nets integration requests.
     *
     * @return array<int, string>
     */
    protected function webhookEvents(): array
    {
        $events = $this->env('NETS_TEST_WEBHOOK_EVENTS');

        if ($events === null) {
            return [
                'payment.created',
                'payment.checkout.completed',
                'payment.charge.created.v2',
                'payment.charge.failed.v2',
                'payment.reservation.failed',
            ];
        }

        return array_values(array_filter(array_map('trim', explode(',', $events))));
    }

    protected function webhookAuthorization(): string
    {
        return $this->env('NETS_TEST_WEBHOOK_AUTHORIZATION') ?? 'cashier-nets-integration-webhook-secret';
    }

    /**
     * Assert that Nets persisted the payment fields this package relies on.
     *
     * @param  array<string, mixed>  $payment
     */
    protected function assertRetrievedPaymentMatchesCheckout(array $payment, string $paymentId, string $reference): void
    {
        $this->assertSame($paymentId, data_get($payment, 'payment.paymentId', data_get($payment, 'paymentId')));
        $this->assertSame($this->testAmount(), (int) data_get($payment, 'payment.orderDetails.amount'));
        $this->assertSame($this->testCurrency(), data_get($payment, 'payment.orderDetails.currency'));
        $this->assertSame($reference, data_get($payment, 'payment.orderDetails.reference'));
        $this->assertIsString(data_get($payment, 'payment.created'));
    }

    /**
     * Assert that Nets stored a checkout URL without requiring an exact hosted URL.
     *
     * @param  array<string, mixed>  $payment
     */
    protected function assertPaymentCheckoutUrl(array $payment, ?string $expectedUrl): void
    {
        $url = data_get($payment, 'payment.checkout.url');

        $this->assertIsString($url);
        $this->assertNotSame('', $url);

        if ($expectedUrl !== null && ! str_contains($expectedUrl, 'hostedpaymentpage')) {
            $this->assertSame($expectedUrl, $url);
        }
    }

    /**
     * Assert that Nets persisted the merchant payment reference.
     *
     * @param  array<string, mixed>  $payment
     */
    protected function assertRetrievedPaymentMyReference(array $payment, string $expected): void
    {
        $actual = $this->paymentMyReference($payment);

        $this->assertSame($expected, $actual, 'Nets did not return the expected myReference. Payment payload: '.json_encode($payment));
    }

    /**
     * Read myReference from known Nets payment response shapes.
     *
     * @param  array<string, mixed>  $payment
     */
    protected function paymentMyReference(array $payment): ?string
    {
        foreach ([
            'payment.myReference',
            'payment.myreference',
            'payment.my_reference',
            'myReference',
        ] as $path) {
            $value = data_get($payment, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Read invoiceNumber from known Nets payment response shapes.
     *
     * @param  array<string, mixed>  $payment
     */
    protected function paymentInvoiceNumber(array $payment): ?string
    {
        foreach ([
            'payment.invoiceNumber',
            'payment.invoice.invoiceNumber',
            'payment.paymentDetails.invoiceDetails.invoiceNumber',
            'payment.charge.invoiceNumber',
            'payment.orderDetails.invoiceNumber',
            'invoiceNumber',
        ] as $path) {
            $value = data_get($payment, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    protected function testAmount(): int
    {
        return (int) (getenv('NETS_TEST_AMOUNT') ?: 1000);
    }

    protected function testCurrency(): string
    {
        return strtoupper((string) (getenv('NETS_TEST_CURRENCY') ?: 'DKK'));
    }

    protected function testEndDate(): string
    {
        return $this->env('NETS_TEST_END_DATE') ?? now()->addYear()->toRfc3339String();
    }

    protected function testReference(string $suffix): string
    {
        return 'cashier-nets-'.$suffix.'-'.Str::uuid();
    }

    protected function testMerchantReference(string $suffix): string
    {
        return 'cn-'.$suffix.'-'.Str::lower(Str::random(12));
    }

    protected function urlFromEnv(string $key, string $default): string
    {
        return $this->env($key) ?? $default;
    }

    protected function env(string $key): ?string
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
