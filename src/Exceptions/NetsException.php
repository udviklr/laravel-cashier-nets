<?php

namespace Udviklr\CashierNets\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

final class NetsException extends Exception
{
    /**
     * Create an exception instance from a failed HTTP response.
     */
    public static function fromResponse(Response $response): self
    {
        $payload = $response->json();

        $message = 'Nets API request failed with status '.$response->status().'.';

        if (is_array($payload)) {
            $message = static::messageFromPayload($payload, $message);
        }

        return new self($message, $response->status());
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
