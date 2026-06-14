<?php

namespace Udviklr\CashierNets\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\NetsException;
use Udviklr\CashierNets\Exceptions\RefundException;

class NetsClient
{
    /**
     * Perform a Nets Payment API request.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  array{idempotency_key?: string, headers?: array<string, string>, exception?: class-string<\Udviklr\CashierNets\Exceptions\NetsException>}  $options
     *
     * @throws \Udviklr\CashierNets\Exceptions\NetsException
     */
    public function request(string $method, string $uri, ?array $payload = null, array $options = []): Response
    {
        $secretKey = config('cashier-nets.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            throw new RuntimeException('Nets secret key not set.');
        }

        $headers = array_merge([
            'Authorization' => $secretKey,
            'Accept' => 'application/json',
        ], $options['headers'] ?? []);

        if (isset($options['idempotency_key'])) {
            $headers['Idempotency-Key'] = $options['idempotency_key'];
        }

        $response = Http::withHeaders($headers)
            ->withUserAgent('Udviklr Cashier Nets/'.CashierNets::VERSION)
            ->send(strtoupper($method), $this->url($uri), [
                'json' => $payload,
            ]);

        if ($response->failed()) {
            /** @var class-string<NetsException> $exceptionClass */
            $exceptionClass = $options['exception'] ?? NetsException::class;

            throw $exceptionClass::fromResponse($response);
        }

        return $response;
    }

    /**
     * Refund a settled Nets charge.
     *
     * A full refund is the charge amount with no order items. A partial refund
     * requires a complete order-item line spec, mirroring the charge payload.
     *
     * @param  int  $amount  minor units; must be greater than zero
     * @param  array<int, array<string, mixed>>  $orderItems  required by Nets only for partial refunds
     * @return array<string, mixed>  the decoded response, including 'refundId'
     *
     * @throws \Udviklr\CashierNets\Exceptions\RefundException
     */
    public function refundCharge(string $chargeId, int $amount, string $idempotencyKey, array $orderItems = []): array
    {
        $chargeId = trim($chargeId);

        if ($chargeId === '') {
            throw new InvalidArgumentException('A Nets charge ID is required to issue a refund.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('A refund amount in minor units greater than zero is required.');
        }

        $payload = ['amount' => $amount];

        if ($orderItems !== []) {
            $payload['orderItems'] = array_values($orderItems);
        }

        $response = $this->request('POST', 'v1/charges/'.$chargeId.'/refunds', $payload, [
            'idempotency_key' => $idempotencyKey,
            'exception' => RefundException::class,
        ]);

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Format a Payment API path into a full URL.
     */
    public function url(string $uri): string
    {
        return CashierNets::apiUrl().Str::start($uri, '/');
    }

    /**
     * Retrieve a nested response value from a Nets response.
     *
     * @template TDefault
     *
     * @param  TDefault  $default
     * @return mixed|TDefault
     */
    public function value(Response $response, string $key, mixed $default = null): mixed
    {
        return Arr::get($response->json(), $key, $default);
    }
}
