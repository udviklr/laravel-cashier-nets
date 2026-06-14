<?php

namespace Udviklr\CashierNets\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;
use Throwable;

class NetsException extends Exception
{
    /**
     * The decoded Nets error response body, when available.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $body;

    /**
     * The constructor is final so fromResponse()'s `new static(...)` is valid for
     * every subclass (e.g. RefundException). Note the signature intentionally
     * inserts $body before $previous, diverging from the base Exception's
     * (message, code, previous): always construct via fromResponse(), not by hand.
     *
     * @param  array<string, mixed>|null  $body
     */
    final public function __construct(string $message = '', int $code = 0, ?array $body = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->body = $body;
    }

    /**
     * Create an exception instance from a failed HTTP response.
     */
    public static function fromResponse(Response $response): static
    {
        $payload = $response->json();

        $message = 'Nets API request failed with status '.$response->status().'.';

        if (is_array($payload)) {
            $message = static::messageFromPayload($payload, $message);
        }

        return new static($message, $response->status(), is_array($payload) ? $payload : null);
    }

    /**
     * Get the decoded Nets error response body, when available.
     *
     * @return array<string, mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }

    /**
     * Extract the most useful message from a Nets error payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function messageFromPayload(array $payload, string $default): string
    {
        if (isset($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }

        if (isset($payload['error']['message']) && is_string($payload['error']['message'])) {
            return $payload['error']['message'];
        }

        if (isset($payload['error']) && is_string($payload['error'])) {
            return $payload['error'];
        }

        if (isset($payload['errors']) && is_array($payload['errors'])) {
            return json_encode($payload['errors']) ?: $default;
        }

        return $default;
    }
}
