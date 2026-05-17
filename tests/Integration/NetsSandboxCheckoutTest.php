<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Subscription;
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

        $checkout = $user->newNetsSubscription('integration-hosted')
            ->amount($this->testAmount())
            ->currency($this->testCurrency())
            ->intervalDays(30)
            ->description('Cashier Nets sandbox hosted subscription')
            ->reference($reference)
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

        $payment = CashierNets::api('GET', 'v1/payments/'.$checkout->paymentId())->json();

        $this->assertSame($checkout->paymentId(), data_get($payment, 'payment.paymentId', data_get($payment, 'paymentId')));
    }

    public function test_it_can_create_an_embedded_subscription_checkout_in_the_nets_sandbox(): void
    {
        $this->configureNetsSandbox(requireCheckoutKey: true);

        $user = $this->createBillableUser();
        $reference = $this->testReference('embedded');

        $checkout = $user->newNetsSubscription('integration-embedded')
            ->amount($this->testAmount())
            ->currency($this->testCurrency())
            ->intervalDays(30)
            ->description('Cashier Nets sandbox embedded subscription')
            ->reference($reference)
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
    }

    protected function configureNetsSandbox(bool $requireCheckoutKey = false): void
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
            'cashier-nets.webhook_events' => [],
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
