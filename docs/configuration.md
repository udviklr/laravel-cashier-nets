# Configuration

Publish the configuration file:

```shell
php artisan vendor:publish --tag="cashier-nets-config"
```

Add your Nets credentials and environment settings to `.env`:

```ini
NETS_SECRET_KEY=your-secret-api-key
NETS_CHECKOUT_KEY=your-checkout-key
NETS_SANDBOX=true
NETS_WEBHOOK_AUTHORIZATION=your-random-webhook-secret
```

## Credentials

`NETS_SECRET_KEY` is used for server-to-server Payment API calls. Never expose it to browsers.

`NETS_CHECKOUT_KEY` is used by embedded checkout frontend code. It may be exposed client-side when initializing Nexi Checkout JS.

## Sandbox and Live Mode

The package uses sandbox mode by default:

```ini
NETS_SANDBOX=true
```

Set `NETS_SANDBOX=false` when you are ready to use live credentials. Sandbox and live credentials are separate; do not reuse test credentials in production.

The default API URLs are:

| Setting | Default |
| --- | --- |
| `NETS_SANDBOX_API_URL` | `https://test.api.dibspayment.eu` |
| `NETS_API_URL` | `https://api.dibspayment.eu` |
| `NETS_SANDBOX_CHECKOUT_JS_URL` | `https://test.checkout.dibspayment.eu/v1/checkout.js?v=1` |
| `NETS_CHECKOUT_JS_URL` | `https://checkout.dibspayment.eu/v1/checkout.js?v=1` |

Only override these when Nexi instructs you to use a different endpoint.

## Routes

By default, the package registers this route:

```text
POST /nets/webhook
```

The route prefix and path are controlled by:

```php
'registers_routes' => true,
'route_prefix' => 'nets',
'webhook_path' => 'webhook',
```

You can disable automatic route registration:

```php
// config/cashier-nets.php
'registers_routes' => false,
```

Or during application boot:

```php
use Udviklr\CashierNets\CashierNets;

CashierNets::$registersRoutes = false;
```

If you disable the default route, register your own route to `Udviklr\CashierNets\Http\Controllers\WebhookController`.

## Webhook Authorization

Set a shared authorization value:

```ini
NETS_WEBHOOK_AUTHORIZATION=your-random-webhook-secret
```

When the package creates Nets payments or subscription charges, it includes this value in each webhook notification. Nexi sends the same value as the incoming `Authorization` header, and the package compares it exactly.

If `NETS_WEBHOOK_AUTHORIZATION` is empty, the package accepts incoming webhooks without this header. That can be useful in isolated tests, but production applications should set it.

## Webhook Events

The default configured Nets events are:

```php
'webhook_events' => [
    'payment.created',
    'payment.checkout.completed',
    'payment.charge.created.v2',
    'payment.charge.failed.v2',
    'payment.reservation.failed',
],
```

These events are attached to payments and subscription charges created by the package.

## Retry Policy

Failed renewal charge retry behavior is controlled by:

```php
'retry_policy' => [
    'max_attempts' => 15,
    'window_days' => 30,
    'non_retryable_response_codes' => [
        '04',
        '14',
        '15',
        '41',
        '43',
        '46',
        '54',
        '57',
    ],
],
```

The package blocks retries for configured non-retryable response codes and limits failed retry attempts within the rolling window.

## Custom Models

You may extend the package models and assign your custom classes during application boot:

```php
use App\Models\Billing\Subscription;
use App\Models\Billing\Transaction;
use App\Models\Billing\WebhookEvent;
use Udviklr\CashierNets\CashierNets;

public function boot(): void
{
    CashierNets::$subscriptionModel = Subscription::class;
    CashierNets::$transactionModel = Transaction::class;
    CashierNets::$webhookEventModel = WebhookEvent::class;
}
```

Custom models should extend the corresponding package models so relationships, casts, and helpers continue to work.

