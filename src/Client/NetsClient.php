<?php

namespace Udviklr\CashierNets\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Exceptions\NetsException;

class NetsClient
{
    /**
     * Perform a Nets Payment API request.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  array{idempotency_key?: string, headers?: array<string, string>}  $options
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
            throw NetsException::fromResponse($response);
        }

        return $response;
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
