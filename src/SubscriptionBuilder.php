<?php

namespace Udviklr\CashierNets;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class SubscriptionBuilder
{
    protected const MAX_MY_REFERENCE_LENGTH = 36;

    protected const HOSTED_CHECKOUT = 'HostedPaymentPage';

    protected const EMBEDDED_CHECKOUT = 'EmbeddedCheckout';

    protected int $amount;

    protected string $currency = 'DKK';

    protected int $intervalDays = 30;

    protected string $description = 'Subscription';

    protected string $reference = 'subscription';

    protected ?string $myReference = null;

    protected ?string $returnUrl = null;

    protected ?string $cancelUrl = null;

    protected ?string $checkoutUrl = null;

    protected ?string $termsUrl = null;

    protected ?bool $merchantHandlesConsumerData = null;

    protected ?CarbonInterface $endDate = null;

    protected bool $initialCharge = false;

    protected string $integrationType = self::HOSTED_CHECKOUT;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $orderItems = [];

    /**
     * Additional metadata to store locally.
     *
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    /**
     * Create a new subscription builder.
     */
    public function __construct(
        protected Model $billable,
        protected string $type = Subscription::DEFAULT_TYPE,
    ) {
    }

    /**
     * Set the recurring amount in minor currency units.
     */
    public function amount(int $amount): self
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('The subscription amount must not be negative.');
        }

        $this->amount = $amount;

        return $this;
    }

    /**
     * Set the currency.
     */
    public function currency(string $currency): self
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    /**
     * Set the minimum interval between recurring charges.
     */
    public function intervalDays(int $days): self
    {
        if ($days < 0) {
            throw new InvalidArgumentException('The subscription interval must not be negative.');
        }

        $this->intervalDays = $days;

        return $this;
    }

    /**
     * Set the checkout/order description.
     */
    public function description(string $description): self
    {
        $this->description = $description;
        $this->reference = $description;

        return $this;
    }

    /**
     * Set the order reference.
     */
    public function reference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * Set the merchant payment reference.
     */
    public function myReference(string $reference): self
    {
        $reference = trim($reference);

        if (strlen($reference) > self::MAX_MY_REFERENCE_LENGTH) {
            throw new InvalidArgumentException('The Nets myReference value may not be greater than 36 characters.');
        }

        $this->myReference = $reference;

        return $this;
    }

    /**
     * Set the merchant payment reference.
     */
    public function merchantReference(string $reference): self
    {
        return $this->myReference($reference);
    }

    /**
     * Set the return URL.
     */
    public function returnUrl(string $url): self
    {
        $this->returnUrl = $url;

        return $this;
    }

    /**
     * Set the cancel URL.
     */
    public function cancelUrl(string $url): self
    {
        $this->cancelUrl = $url;

        return $this;
    }

    /**
     * Set the embedded checkout page URL.
     */
    public function checkoutUrl(string $url): self
    {
        $this->checkoutUrl = $url;

        return $this;
    }

    /**
     * Set the terms URL.
     */
    public function termsUrl(string $url): self
    {
        $this->termsUrl = $url;

        return $this;
    }

    /**
     * Let the merchant handle consumer data outside Nets checkout.
     */
    public function merchantHandlesConsumerData(bool $enabled = true): self
    {
        $this->merchantHandlesConsumerData = $enabled;

        return $this;
    }

    /**
     * Set the Nets subscription end date.
     */
    public function endDate(CarbonInterface|DateTimeInterface|string $date): self
    {
        $this->endDate = $date instanceof CarbonInterface
            ? $date
            : now()->parse($date);

        return $this;
    }

    /**
     * Charge the customer when the subscription is created.
     */
    public function chargeImmediately(bool $charge = true): self
    {
        $this->initialCharge = $charge;

        return $this;
    }

    /**
     * Add local metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Set explicit Nets order items.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function orderItems(array $items): self
    {
        if ($items === []) {
            throw new InvalidArgumentException('At least one order item is required.');
        }

        $this->orderItems = array_values($items);

        return $this;
    }

    /**
     * Create a hosted Nets subscription checkout.
     */
    public function checkout(array $options = []): Checkout
    {
        return $this->hostedCheckout($options);
    }

    /**
     * Create a hosted Nets subscription checkout.
     *
     * @param  array<string, mixed>  $options
     */
    public function hostedCheckout(array $options = []): Checkout
    {
        $this->integrationType = self::HOSTED_CHECKOUT;

        return $this->createCheckout($options);
    }

    /**
     * Create an embedded Nets subscription checkout.
     *
     * @param  array<string, mixed>  $options
     */
    public function embeddedCheckout(array $options = []): Checkout
    {
        $this->integrationType = self::EMBEDDED_CHECKOUT;

        return $this->createCheckout($options);
    }

    /**
     * Create a Nets subscription checkout.
     *
     * @param  array<string, mixed>  $options
     */
    protected function createCheckout(array $options = []): Checkout
    {
        $this->validate();

        $payload = array_replace_recursive($this->payload(), $options);
        $response = CashierNets::api('POST', 'v1/payments', $payload)->json();

        if (! is_array($response) || ! isset($response['paymentId'])) {
            throw new InvalidArgumentException('The Nets create payment response did not contain a paymentId.');
        }

        $subscription = $this->storePendingSubscription((string) $response['paymentId']);

        return new Checkout(
            (string) $response['paymentId'],
            isset($response['hostedPaymentPageUrl']) ? (string) $response['hostedPaymentPageUrl'] : null,
            $response,
            $subscription,
        );
    }

    /**
     * Build the create-payment payload.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $checkout = [
            'integrationType' => $this->integrationType,
            'charge' => $this->initialCharge,
        ];

        if ($this->integrationType === self::HOSTED_CHECKOUT) {
            $checkout['returnUrl'] = $this->returnUrl;
        }

        if ($this->integrationType === self::EMBEDDED_CHECKOUT) {
            $checkout['url'] = $this->checkoutUrl;
        }

        if ($this->integrationType === self::HOSTED_CHECKOUT && $this->cancelUrl !== null) {
            $checkout['cancelUrl'] = $this->cancelUrl;
        }

        if ($this->termsUrl !== null) {
            $checkout['termsUrl'] = $this->termsUrl;
        }

        if ($this->merchantHandlesConsumerData !== null) {
            $checkout['merchantHandlesConsumerData'] = $this->merchantHandlesConsumerData;
        }

        $payload = [
            'checkout' => $checkout,
            'order' => [
                'items' => $this->orderItems !== [] ? $this->orderItems : [$this->orderItem()],
                'amount' => $this->amount,
                'currency' => $this->currency,
                'reference' => $this->reference,
            ],
            'subscription' => [
                'interval' => $this->intervalDays,
            ],
            'notifications' => [
                'webHooks' => $this->webhooks(),
            ],
        ];

        if ($this->endDate !== null) {
            $payload['subscription']['endDate'] = $this->endDate->toRfc3339String();
        }

        if ($payload['notifications']['webHooks'] === []) {
            unset($payload['notifications']);
        }

        if ($this->myReference !== null) {
            $payload['myReference'] = $this->myReference;
        }

        return $payload;
    }

    /**
     * Validate the builder state before creating checkout.
     */
    protected function validate(): void
    {
        if (! isset($this->amount)) {
            throw new InvalidArgumentException('A subscription amount is required.');
        }

        if ($this->orderItems !== []) {
            CashierNets::assertOrderItemsConsistent($this->orderItems, $this->amount);
        }

        if ($this->integrationType === self::HOSTED_CHECKOUT && $this->returnUrl === null) {
            throw new InvalidArgumentException('A return URL is required.');
        }

        if ($this->integrationType === self::EMBEDDED_CHECKOUT && $this->checkoutUrl === null) {
            throw new InvalidArgumentException('A checkout URL is required.');
        }

        if ($this->endDate === null) {
            throw new InvalidArgumentException('A subscription end date is required.');
        }
    }

    /**
     * Build the order item payload.
     *
     * @return array<string, mixed>
     */
    protected function orderItem(): array
    {
        return [
            'reference' => $this->reference,
            'name' => $this->description,
            'quantity' => 1,
            'unit' => 'pcs',
            'unitPrice' => $this->amount,
            'taxRate' => 0,
            'taxAmount' => 0,
            'grossTotalAmount' => $this->amount,
            'netTotalAmount' => $this->amount,
        ];
    }

    /**
     * Build the webhook notification payload.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function webhooks(): array
    {
        return CashierNets::webhooks();
    }

    /**
     * Store the local pending subscription.
     */
    protected function storePendingSubscription(string $paymentId): Subscription
    {
        $metadata = $this->metadata;

        if ($this->orderItems !== []) {
            $metadata['order_items'] = $this->orderItems;
        }

        /** @var \Udviklr\CashierNets\Subscription $subscription */
        $subscription = $this->billable->morphMany(CashierNets::$subscriptionModel, 'billable')->updateOrCreate([
            'type' => $this->type,
        ], [
            'nets_payment_id' => $paymentId,
            'status' => Subscription::STATUS_PENDING,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'interval_days' => $this->intervalDays,
            'next_charge_at' => now()->addDays(max(1, $this->intervalDays)),
            'metadata' => $metadata,
        ]);

        if ($this->myReference !== null) {
            $subscription->forceFill([
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'my_reference' => $this->myReference,
                ]),
            ])->save();
        }

        return $subscription;
    }
}
