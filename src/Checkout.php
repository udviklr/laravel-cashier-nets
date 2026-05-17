<?php

namespace Udviklr\CashierNets;

use Illuminate\Http\RedirectResponse;

class Checkout
{
    /**
     * Create a checkout result.
     *
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        protected string $paymentId,
        protected ?string $url,
        protected array $response,
        protected ?Subscription $subscription = null,
    ) {
    }

    /**
     * Get the Nets payment identifier.
     */
    public function paymentId(): string
    {
        return $this->paymentId;
    }

    /**
     * Get the hosted checkout URL.
     */
    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * Get the related local subscription, if any.
     */
    public function subscription(): ?Subscription
    {
        return $this->subscription;
    }

    /**
     * Get the raw Nets response payload.
     *
     * @return array<string, mixed>
     */
    public function response(): array
    {
        return $this->response;
    }

    /**
     * Create a redirect response to the hosted checkout URL.
     */
    public function redirect(): RedirectResponse
    {
        if ($this->url === null) {
            throw new \RuntimeException('This checkout does not have a hosted checkout URL.');
        }

        return redirect()->away((string) $this->url);
    }
}
