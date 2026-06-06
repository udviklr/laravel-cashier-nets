<?php

namespace Udviklr\CashierNets;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Udviklr\CashierNets\Client\NetsClient;

class CashierNets
{
    public const VERSION = '1.0.0';

    /**
     * Indicates if Cashier Nets routes will be registered.
     */
    public static bool $registersRoutes = true;

    /**
     * Indicates if past due subscriptions should be considered invalid.
     */
    public static bool $deactivatePastDue = true;

    /**
     * The customer model class name.
     *
     * @var class-string<\Udviklr\CashierNets\Customer>
     */
    public static string $customerModel = Customer::class;

    /**
     * The subscription model class name.
     *
     * @var class-string<\Udviklr\CashierNets\Subscription>
     */
    public static string $subscriptionModel = Subscription::class;

    /**
     * The transaction model class name.
     *
     * @var class-string<\Udviklr\CashierNets\Transaction>
     */
    public static string $transactionModel = Transaction::class;

    /**
     * The webhook event model class name.
     *
     * @var class-string<\Udviklr\CashierNets\WebhookEvent>
     */
    public static string $webhookEventModel = WebhookEvent::class;

    /**
     * Get a configured Nets API client instance.
     */
    public static function client(): NetsClient
    {
        return app(NetsClient::class);
    }

    /**
     * Create a new subscription model instance.
     */
    public static function subscriptionModel(): Subscription
    {
        $class = static::$subscriptionModel;

        return new $class;
    }

    /**
     * Create a new transaction model instance.
     */
    public static function transactionModel(): Transaction
    {
        $class = static::$transactionModel;

        return new $class;
    }

    /**
     * Perform a Nets Payment API request.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  array{idempotency_key?: string, headers?: array<string, string>}  $options
     */
    public static function api(string $method, string $uri, ?array $payload = null, array $options = []): Response
    {
        return static::client()->request($method, $uri, $payload, $options);
    }

    /**
     * Fake Nets API responses and package events.
     */
    public static function fake(...$arguments): CashierNetsFake
    {
        return CashierNetsFake::fake(...$arguments);
    }

    /**
     * Assert that a webhook received event was dispatched.
     */
    public static function assertWebhookReceived(callable|int|null $callback = null): void
    {
        CashierNetsFake::assertWebhookReceived($callback);
    }

    /**
     * Assert that a webhook handled event was dispatched.
     */
    public static function assertWebhookHandled(callable|int|null $callback = null): void
    {
        CashierNetsFake::assertWebhookHandled($callback);
    }

    /**
     * Get the configured Nets Payment API base URL.
     */
    public static function apiUrl(): string
    {
        return rtrim((string) (config('cashier-nets.sandbox')
            ? config('cashier-nets.api_urls.sandbox')
            : config('cashier-nets.api_urls.live')), '/');
    }

    /**
     * Get the configured Nets Checkout JS URL.
     */
    public static function checkoutJsUrl(): string
    {
        return (string) (config('cashier-nets.sandbox')
            ? config('cashier-nets.checkout_js_urls.sandbox')
            : config('cashier-nets.checkout_js_urls.live'));
    }

    /**
     * Get the configured Nets Checkout key for embedded checkout.
     */
    public static function checkoutKey(): ?string
    {
        $checkoutKey = config('cashier-nets.checkout_key');

        return is_string($checkoutKey) && $checkoutKey !== '' ? $checkoutKey : null;
    }

    /**
     * Get the package webhook URL.
     */
    public static function webhookUrl(): string
    {
        return route('cashier-nets.webhook');
    }

    /**
     * Get the configured Nets webhook notification payload.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function webhooks(): array
    {
        $events = Arr::wrap(config('cashier-nets.webhook_events', []));

        if ($events === []) {
            return [];
        }

        return collect($events)->map(function (string $eventName): array {
            $webhook = [
                'eventName' => $eventName,
                'url' => static::webhookUrl(),
            ];

            if (config('cashier-nets.webhook_authorization')) {
                $webhook['authorization'] = config('cashier-nets.webhook_authorization');
            }

            return $webhook;
        })->values()->all();
    }

    /**
     * Format the given minor-unit amount for display.
     */
    public static function formatAmount(int $amount, string $currency, ?string $locale = null): string
    {
        $currency = strtoupper($currency);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale ?? config('app.locale', 'en'), \NumberFormatter::CURRENCY);

            return $formatter->formatCurrency($amount / 100, $currency);
        }

        return $currency.' '.number_format($amount / 100, 2);
    }

    /**
     * Assert that custom Nets order items are internally consistent and total the order amount.
     *
     * Enforces the exact integer invariants Nets applies to order.items, so a malformed item
     * fails locally instead of being rejected by Nets at checkout or, worse, on a later
     * recurring charge (which would otherwise flip the subscription to past due):
     *
     *   - netTotalAmount   = unitPrice * quantity   (unitPrice excludes VAT)
     *   - grossTotalAmount = netTotalAmount + taxAmount
     *   - order amount     = sum of every item grossTotalAmount
     *
     * The taxAmount = netTotalAmount * taxRate / 10000 relationship is intentionally NOT
     * enforced: that division may round per unit, so the caller owns the rounded value.
     * For the same reason netTotalAmount is only checked against unitPrice * quantity when
     * the quantity is a whole number; fractional units (e.g. weight) are left to Nets.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function assertOrderItemsConsistent(array $items, int $amount): void
    {
        $grossTotal = 0;

        foreach ($items as $item) {
            $tax = $item['taxAmount'] ?? 0;
            $net = $item['netTotalAmount'] ?? null;
            $gross = $item['grossTotalAmount'] ?? null;

            if (! is_int($tax) || ! is_int($net) || ! is_int($gross)) {
                throw new InvalidArgumentException(
                    'Each Nets order item must define integer netTotalAmount, taxAmount and grossTotalAmount values in minor units.'
                );
            }

            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unitPrice'] ?? null;

            if (is_int($quantity) && is_int($unitPrice) && $net !== $unitPrice * $quantity) {
                throw new InvalidArgumentException(
                    'Nets order item netTotalAmount must equal unitPrice times quantity (unitPrice excludes VAT).'
                );
            }

            if ($gross !== $net + $tax) {
                throw new InvalidArgumentException(
                    'Nets order item grossTotalAmount must equal netTotalAmount plus taxAmount.'
                );
            }

            $grossTotal += $gross;
        }

        if ($grossTotal !== $amount) {
            throw new InvalidArgumentException(
                'The Nets order item gross totals ('.$grossTotal.') must equal the order amount ('.$amount.').'
            );
        }
    }
}
