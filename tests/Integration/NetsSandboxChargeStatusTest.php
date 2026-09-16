<?php

namespace Tests\Integration;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\NetsException;

/**
 * Read-only provider contract checks for the uncertain-charge implementation gate.
 *
 * Fixtures are existing sandbox attempts, never charges created by these tests.
 * Each request records a small, credential-free observation before asserting the
 * contract, so a failed assertion still leaves evidence for the API notes.
 */
#[Group('integration')]
#[Group('nets-charge-status')]
class NetsSandboxChargeStatusTest extends TestCase
{
    public function test_it_observes_an_unseen_key_on_an_existing_subscription(): void
    {
        $subscriptionId = $this->configureSandbox();
        $key = 'cashier-status-unseen-'.Str::uuid();

        [$status, $body] = $this->retrieveStatus($subscriptionId, $key, 'unseen');

        $this->assertSame(404, $status);
        $this->assertNull($body);
    }

    public function test_it_recovers_a_completed_attempt_with_a_63_character_order_reference(): void
    {
        $subscriptionId = $this->configureSandbox();
        $key = $this->requiredEnvironment('NETS_TEST_STATUS_SUCCESS_KEY');
        $this->assertSame(63, strlen($key), 'Use a fixture exercising the supported key-length boundary.');

        [$status, $body] = $this->retrieveStatus($subscriptionId, $key, 'completed');

        $this->assertSame(200, $status);
        $this->assertIsArray($body);
        $this->assertSame(true, $body['completed'] ?? null);
        $this->assertProviderId($body['paymentId'] ?? null);
        $this->assertProviderId($body['chargeId'] ?? null);

        $payment = $this->retrievePayment($body['paymentId'], 'completed');
        $this->assertPaymentIdentity($payment, $subscriptionId, $key, 1000);

        $charge = collect($payment['charges'] ?? [])->firstWhere('chargeId', $body['chargeId']);
        $this->assertIsArray($charge, 'The recovered charge must appear in the retrieved payment.');
        $this->assertProviderId($charge['created'] ?? null);
        $this->assertNotNull(Carbon::parse($charge['created']));
    }

    public function test_a_declined_key_is_404_but_its_payment_preserves_attempt_identity(): void
    {
        $subscriptionId = $this->configureSandbox();
        $key = $this->requiredEnvironment('NETS_TEST_STATUS_DECLINED_KEY');
        $paymentId = $this->requiredEnvironment('NETS_TEST_STATUS_DECLINED_PAYMENT_ID');
        $this->assertSame(63, strlen($key));

        [$status, $body] = $this->retrieveStatus($subscriptionId, $key, 'declined');

        // A 404 supplies no outcome. The payment ID comes from a captured
        // webhook or a validated candidate in the original response message.
        $this->assertSame(404, $status);
        $this->assertNull($body);

        $payment = $this->retrievePayment($paymentId, 'declined');
        $this->assertPaymentIdentity($payment, $subscriptionId, $key, 114);
    }

    public function test_the_success_webhook_identifies_a_retrievable_charge(): void
    {
        $subscriptionId = $this->configureSandbox();
        $key = $this->requiredEnvironment('NETS_TEST_STATUS_SUCCESS_KEY');
        $paymentId = $this->requiredEnvironment('NETS_TEST_STATUS_SUCCESS_PAYMENT_ID');
        $event = $this->capturedEvent('payment.charge.created.v2', $paymentId);
        $payment = $this->retrievePayment($paymentId, 'success_webhook');

        $this->assertPaymentIdentity($payment, $subscriptionId, $key, 1000);
        $this->assertSame($subscriptionId, data_get($event, 'data.subscriptionId'));
        $chargeId = data_get($event, 'data.chargeId');
        $this->assertProviderId($chargeId);
        $this->assertIsArray(collect($payment['charges'] ?? [])->firstWhere('chargeId', $chargeId));
        $this->assertSame(1000, data_get($event, 'data.amount.amount'));
        $this->assertSame('DKK', data_get($event, 'data.amount.currency'));
    }

    public function test_the_synchronous_decline_delivers_a_failure_webhook_with_a_validatable_payment_id(): void
    {
        $subscriptionId = $this->configureSandbox();
        $key = $this->requiredEnvironment('NETS_TEST_STATUS_DECLINED_KEY');
        $paymentId = $this->requiredEnvironment('NETS_TEST_STATUS_DECLINED_PAYMENT_ID');
        $event = $this->capturedEvent('payment.reservation.failed', $paymentId);
        $payment = $this->retrievePayment($paymentId, 'failure_webhook');

        // The observed event lacks subscriptionId and the top-level order
        // reference. Recover both from the payment, not from orderItems.
        $this->assertPaymentIdentity($payment, $subscriptionId, $key, 114);
        $this->assertSame('14', data_get($event, 'data.error.code'));
        $this->assertSame('Issuer', data_get($event, 'data.error.source'));
        $this->assertSame(114, data_get($event, 'data.amount.amount'));
        $this->assertSame('DKK', data_get($event, 'data.amount.currency'));
        $this->assertProviderId($event['timestamp'] ?? null);
        $this->assertNotNull(Carbon::parse($event['timestamp']));
    }

    public function test_payment_created_webhooks_distinguish_attempt_keys_from_item_references(): void
    {
        $subscriptionId = $this->configureSandbox();

        foreach (['SUCCESS', 'DECLINED'] as $fixture) {
            $key = $this->requiredEnvironment('NETS_TEST_STATUS_'.$fixture.'_KEY');
            $paymentId = $this->requiredEnvironment('NETS_TEST_STATUS_'.$fixture.'_PAYMENT_ID');
            $event = $this->capturedEvent('payment.created', $paymentId);

            $this->assertSame($subscriptionId, data_get($event, 'data.subscriptionId'));
            $this->assertSame($key, data_get($event, 'data.order.reference'));
            $this->assertProviderId(data_get($event, 'data.order.orderItems.0.reference'));
            $this->assertNotSame($key, data_get($event, 'data.order.orderItems.0.reference'));
        }
    }

    public function test_it_can_still_resolve_a_completed_key_after_24_hours(): void
    {
        $subscriptionId = $this->configureSandbox();
        $subscriptionId = getenv('NETS_TEST_STATUS_AGED_SUBSCRIPTION_ID') ?: $subscriptionId;
        $key = $this->requiredEnvironment('NETS_TEST_STATUS_AGED_KEY');
        $paymentId = $this->requiredEnvironment('NETS_TEST_STATUS_AGED_PAYMENT_ID');
        $chargeId = $this->requiredEnvironment('NETS_TEST_STATUS_AGED_CHARGE_ID');

        // Establish the age from provider data before checking retention. A
        // newly-created fixture must not accidentally satisfy the 24-hour gate.
        $payment = $this->retrievePayment($paymentId, 'aged');
        $charge = collect($payment['charges'] ?? [])->firstWhere('chargeId', $chargeId);
        $this->assertIsArray($charge);
        $this->assertProviderId($charge['created'] ?? null);
        $this->assertTrue(Carbon::parse($charge['created'])->lte(now()->subHours(24)), 'Use a charge created at least 24 hours ago.');

        [$status, $body] = $this->retrieveStatus($subscriptionId, $key, 'aged');

        $this->assertSame(200, $status);
        $this->assertIsArray($body);
        $this->assertSame(true, $body['completed'] ?? null);
        $this->assertSame($paymentId, $body['paymentId'] ?? null);
        $this->assertSame($chargeId, $body['chargeId'] ?? null);
    }

    protected function configureSandbox(): string
    {
        if (! filter_var(getenv('NETS_INTEGRATION') ?: false, FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set NETS_INTEGRATION=true to run Nets sandbox integration tests.');
        }

        $secret = $this->requiredEnvironment('NETS_SECRET_KEY');

        if ($secret === 'test-secret-key' || str_starts_with($secret, 'live-')) {
            $this->markTestSkipped('Configure a real sandbox secret key, not a placeholder or live key.');
        }

        config([
            'cashier-nets.secret_key' => $secret,
            'cashier-nets.sandbox' => true,
            'cashier-nets.api_urls.sandbox' => 'https://test.api.dibspayment.eu',
        ]);

        return $this->requiredEnvironment('NETS_TEST_SUBSCRIPTION_ID');
    }

    protected function requiredEnvironment(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || trim($value) === '') {
            $this->markTestSkipped('Set '.$name.' to an existing sandbox fixture.');
        }

        return trim($value);
    }

    /** @return array{int, mixed} */
    protected function retrieveStatus(string $subscriptionId, string $key, string $scenario): array
    {
        [$status, $body] = $this->apiGet('v1/subscriptions/'.rawurlencode($subscriptionId).'/charges/status', $key);

        $this->recordObservation([
            'scenario' => $scenario,
            'operation' => 'subscription_charge_status',
            'subscription_id' => $subscriptionId,
            'idempotency_key' => $key,
            'http_status' => $status,
            'body' => $this->responseSummary($body),
        ]);

        return [$status, $body];
    }

    /** @return array<string, mixed> */
    protected function retrievePayment(string $paymentId, string $scenario): array
    {
        [$status, $body] = $this->apiGet('v1/payments/'.rawurlencode($paymentId));
        $payment = is_array($body) ? ($body['payment'] ?? []) : [];

        $this->recordObservation([
            'scenario' => $scenario,
            'operation' => 'retrieve_payment',
            'http_status' => $status,
            'body' => $this->responseSummary($body),
            'payment' => is_array($payment) ? [
                'fields' => array_keys($payment),
                'paymentId' => $payment['paymentId'] ?? null,
                'created' => $payment['created'] ?? null,
                'myReference' => $payment['myReference'] ?? null,
                'subscription_id' => data_get($payment, 'subscription.id', data_get($payment, 'subscription.subscriptionId')),
                'order_details' => $payment['orderDetails'] ?? null,
                'summary' => $payment['summary'] ?? null,
                'charges' => array_map(fn (array $charge): array => [
                    'fields' => array_keys($charge),
                    'chargeId' => $charge['chargeId'] ?? null,
                    'created' => $charge['created'] ?? null,
                    'amount' => $charge['amount'] ?? null,
                ], $payment['charges'] ?? []),
            ] : null,
        ]);

        $this->assertSame(200, $status);
        $this->assertIsArray($payment);
        $this->assertSame($paymentId, $payment['paymentId'] ?? null);

        return $payment;
    }

    /** @return array{int, mixed} */
    protected function apiGet(string $uri, ?string $key = null): array
    {
        try {
            $response = CashierNets::api('GET', $uri, null, $key === null ? [] : ['idempotency_key' => $key]);

            return [$response->status(), $response->json()];
        } catch (NetsException $exception) {
            return [$exception->getCode(), $exception->body()];
        }
    }

    /** @return array<string, mixed> */
    protected function responseSummary(mixed $body): array
    {
        return [
            'fields' => is_array($body) ? array_keys($body) : [],
            'paymentId' => data_get($body, 'paymentId'),
            'chargeId' => data_get($body, 'chargeId'),
            'completed' => data_get($body, 'completed'),
            'code' => data_get($body, 'code'),
            'source' => data_get($body, 'source'),
            'error_code' => data_get($body, 'error.code'),
            'error_source' => data_get($body, 'error.source'),
        ];
    }

    protected function assertProviderId(mixed $value): void
    {
        $this->assertIsString($value);
        $this->assertNotSame('', trim($value));
    }

    /** @param array<string, mixed> $payment */
    protected function assertPaymentIdentity(array $payment, string $subscriptionId, string $key, int $amount): void
    {
        $this->assertSame($subscriptionId, data_get($payment, 'subscription.id'));
        $this->assertSame($key, data_get($payment, 'orderDetails.reference'));
        $this->assertSame($amount, data_get($payment, 'orderDetails.amount'));
        $this->assertSame('DKK', data_get($payment, 'orderDetails.currency'));
    }

    /** @return array<string, mixed> */
    protected function capturedEvent(string $name, string $paymentId): array
    {
        $path = $this->requiredEnvironment('NETS_TEST_WEBHOOK_CAPTURE_PATH');
        $this->assertFileExists($path);

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $event = json_decode($record['body'], true, flags: JSON_THROW_ON_ERROR);

            if (($event['event'] ?? null) !== $name || data_get($event, 'data.paymentId') !== $paymentId) {
                continue;
            }

            $this->recordObservation([
                'scenario' => 'captured_webhook',
                'operation' => 'read_local_capture',
                'received_at' => $record['received_at'],
                'event' => $name,
                'event_id' => $event['id'] ?? null,
                'timestamp' => $event['timestamp'] ?? null,
                'data_fields' => array_keys($event['data'] ?? []),
                'payment_id' => $paymentId,
                'subscription_id' => data_get($event, 'data.subscriptionId'),
                'charge_id' => data_get($event, 'data.chargeId'),
                'order_reference' => data_get($event, 'data.order.reference'),
                'error_code' => data_get($event, 'data.error.code'),
                'error_source' => data_get($event, 'data.error.source'),
            ]);

            return $event;
        }

        $this->fail('No '.$name.' webhook was captured for payment '.$paymentId.'.');
    }

    /** @param array<string, mixed> $observation */
    protected function recordObservation(array $observation): void
    {
        $path = dirname(__DIR__, 2).'/_private/uncertain-charge-status-evidence.jsonl';

        if (! is_dir(dirname($path))) {
            $this->assertTrue(mkdir(dirname($path), 0700, true));
        }

        // Deliberately exclude credentials, notification configuration, consumer
        // details and card details from the saved observations.
        $line = json_encode(['observed_at' => now()->utc()->toIso8601String()] + $observation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $this->assertNotFalse(file_put_contents($path, $line, FILE_APPEND | LOCK_EX));
    }
}
