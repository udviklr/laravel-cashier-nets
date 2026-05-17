<?php

namespace Udviklr\CashierNets;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Events\CheckoutCompleted;
use Udviklr\CashierNets\Events\WebhookHandled;
use Udviklr\CashierNets\Events\WebhookReceived;

final class CashierNetsFake
{
    /**
     * The fake responses keyed by endpoint.
     *
     * @var array<string, array{body: mixed, status: int, headers: array<string, string>}>
     */
    protected array $responses = [];

    /**
     * Initialize the fake API and package events.
     *
     * @param  array<int|string, mixed>  $endpoints
     * @param  string|array<int, string>  $events
     */
    public function __construct(array $endpoints = [], string|array $events = [])
    {
        foreach ($endpoints as $endpoint => $response) {
            if (! Arr::isAssoc($endpoints)) {
                $endpoint = $response;
                $response = [];
            }

            $this->fakeHttpResponse((string) $endpoint, $response);
        }

        Event::fake(array_merge([
            ChargeFailed::class,
            ChargeSucceeded::class,
            CheckoutCompleted::class,
            WebhookHandled::class,
            WebhookReceived::class,
        ], Arr::wrap($events)));
    }

    /**
     * Syntactic sugar for creating a fake instance.
     */
    public static function fake(...$arguments): self
    {
        return new self(...$arguments);
    }

    /**
     * Set a successful fake response for an endpoint.
     *
     * @param  array<string, mixed>  $data
     */
    public function response(string $endpoint, array $data, int $status = 200): self
    {
        $this->fakeHttpResponse($endpoint, $data, $status);

        return $this;
    }

    /**
     * Set a fake error response for an endpoint.
     *
     * @param  array<string, mixed>|string  $error
     */
    public function error(string $endpoint, array|string $error = 'Nets API error.', int $status = 400): self
    {
        $this->fakeHttpResponse($endpoint, is_array($error) ? $error : [
            'message' => $error,
        ], $status);

        return $this;
    }

    /**
     * Format the given path into a full API URL.
     */
    public static function getFormattedApiUrl(string $path): string
    {
        return CashierNets::apiUrl().Str::start($path, '/');
    }

    /**
     * Assert that a webhook received event was dispatched.
     */
    public static function assertWebhookReceived(callable|int|null $callback = null): void
    {
        Event::assertDispatched(WebhookReceived::class, $callback);
    }

    /**
     * Assert that a webhook handled event was dispatched.
     */
    public static function assertWebhookHandled(callable|int|null $callback = null): void
    {
        Event::assertDispatched(WebhookHandled::class, $callback);
    }

    /**
     * Fake the given endpoint with the provided response.
     *
     * @param  mixed  $response
     */
    protected function fakeHttpResponse(string $endpoint, mixed $response, int $status = 200): void
    {
        $notFaked = ! Arr::exists($this->responses, $endpoint);

        $this->responses[$endpoint] = [
            'body' => $response,
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
        ];

        if ($notFaked) {
            Http::fake([
                static::getFormattedApiUrl($endpoint) => function () use ($endpoint) {
                    $response = $this->responses[$endpoint];

                    return Http::response($response['body'], $response['status'], $response['headers']);
                },
            ]);
        }
    }
}
