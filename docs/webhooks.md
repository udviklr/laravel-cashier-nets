# Webhooks

Webhooks are required for local subscription state to become reliable. Checkout creation stores a pending local subscription; Nets webhook events activate subscriptions, record transactions, and mark failed payments as past due.

## Route

By default, the package registers:

```text
POST /nets/webhook
```

The route name is:

```text
cashier-nets.webhook
```

The package includes this route URL in the webhook notifications attached to created payments and subscription charges.

## CSRF Protection

Nets webhooks are external server-to-server requests, so they do not include Laravel CSRF tokens. Exclude the webhook route from CSRF protection.

For Laravel 11 and newer applications, configure this in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: [
        'nets/*',
    ]);
})
```

If you changed `cashier-nets.route_prefix`, update the excluded path to match.

For Laravel 10 applications, add the path to the `$except` array on your application's CSRF middleware:

```php
protected $except = [
    'nets/*',
];
```

## Webhook Secret

Set a shared secret in `.env`:

```ini
NETS_WEBHOOK_SECRET=your-random-webhook-secret
```

The package sends this value to Nets in the webhook notification payload as Nexi's `authorization` field. Nexi sends it back as the incoming `Authorization` header. The webhook controller rejects requests when the configured value and incoming header do not match.

## Events

The v1 webhook handler processes:

- `payment.created`
- `payment.checkout.completed`
- `payment.charge.created.v2`
- `payment.charge.failed.v2`
- `payment.reservation.failed`

Legacy `payment.charge.created` and `payment.charge.failed` payloads are also handled.

## Idempotency

Received webhook payloads are stored in `nets_webhook_events`. When a payload includes an event ID that has already been processed, the package returns a successful duplicate response and does not process the event again.

## Package Events

The webhook controller always dispatches the generic diagnostic events:

```php
Udviklr\CashierNets\Events\WebhookReceived
Udviklr\CashierNets\Events\WebhookHandled
```

Both events expose the raw webhook payload:

```php
namespace App\Listeners;

use Udviklr\CashierNets\Events\WebhookReceived;

class NetsWebhookListener
{
    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['eventName'] ?? null) === 'payment.checkout.completed') {
            // React to the completed checkout.
        }
    }
}
```

Use application listeners when you need to update app-specific records after a billing event.

For application state sync, prefer the semantic events that are dispatched after the package has persisted the webhook event and synced package-owned models:

```php
Udviklr\CashierNets\Events\CheckoutCompleted
Udviklr\CashierNets\Events\ChargeSucceeded
Udviklr\CashierNets\Events\ChargeFailed
```

These events expose a parsed payload, the stored `WebhookEvent`, and any resolved package `Subscription` or `Transaction`:

```php
namespace App\Listeners;

use Udviklr\CashierNets\Events\ChargeSucceeded;

class SyncSuccessfulNetsCharge
{
    public function handle(ChargeSucceeded $event): void
    {
        $paymentId = $event->payload->paymentId();
        $chargeId = $event->payload->chargeId();
        $subscription = $event->subscription;
        $transaction = $event->transaction;
    }
}
```

Duplicate webhook deliveries still dispatch `WebhookHandled`, but semantic events are only dispatched when the package processes the webhook.

## Parsed Payloads

Use `Udviklr\CashierNets\Webhooks\WebhookPayload` when your application needs to read Nets identifiers from a raw webhook payload:

```php
use Udviklr\CashierNets\Webhooks\WebhookPayload;

$payload = WebhookPayload::from($rawPayload);

$payload->eventName();
$payload->paymentId();
$payload->chargeId();
$payload->subscriptionId();
$payload->amount();
$payload->currency();
$payload->occurredAt();
$payload->raw();
```

Missing identifiers and unparseable timestamps return `null`. Amounts are returned in Nets minor units. `paymentId()` accepts both `data.paymentId` and the defensive lowercase `data.paymentid` variant.

## Local Development

Nexi requires HTTPS webhook endpoints. During local development, expose your app with a secure tunnel such as Ngrok, Expose, Laravel Herd share, or another HTTPS tunnel.

Make sure `APP_URL` points to the public tunnel URL before creating the checkout. The package uses Laravel's route generation for webhook URLs.

When using an HTTPS tunnel in front of a local HTTP server, configure Laravel to trust forwarded proxy headers so generated URLs and asset URLs stay HTTPS. In session-authenticated hosted checkout flows, use `SESSION_SAME_SITE=lax`; `strict` can prevent the Laravel session cookie from being sent on the browser return from Nets.

For local hosted checkout testing, build frontend assets normally instead of relying on Vite HMR through the payment-provider redirect unless your tunnel setup explicitly supports it.

## Troubleshooting

Common webhook issues:

- `419` responses usually mean the webhook route is still protected by CSRF middleware.
- `401` responses mean the incoming `Authorization` header does not match `NETS_WEBHOOK_SECRET`.
- Missing webhook calls often mean `APP_URL` was not public HTTPS when the checkout or charge was created.
- Subscriptions stuck in `pending` usually mean `payment.checkout.completed` has not been received or matched to the local payment ID, or the checkout-completed webhook did not include a Nets subscription ID and the hosted callback has not finalized the subscription yet.
