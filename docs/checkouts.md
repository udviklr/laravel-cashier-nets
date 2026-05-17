# Checkouts

Laravel Cashier Nets supports normal Nets subscriptions through hosted and embedded checkout sessions.

The package does not currently provide one-time checkout helpers, unscheduled subscription helpers, or frontend UI components.

## Hosted Checkout

Hosted checkout redirects the customer to a Nexi-hosted page:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/subscribe', function (Request $request) {
    $checkout = $request->user()->newNetsSubscription('default')
        ->amount(9900)
        ->currency('DKK')
        ->intervalDays(30)
        ->description('Pro plan')
        ->reference('pro-plan')
        ->returnUrl(route('billing.return'))
        ->cancelUrl(route('billing.cancel'))
        ->termsUrl(route('terms'))
        ->endDate(now()->addYear())
        ->hostedCheckout();

    return $checkout->redirect();
});
```

The shorter `checkout()` method is an alias for `hostedCheckout()`.

## Embedded Checkout

Embedded checkout creates the payment object and returns a `paymentId` your frontend can pass to Nexi Checkout JS:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Udviklr\CashierNets\CashierNets;

Route::get('/billing/checkout-session', function (Request $request) {
    $checkout = $request->user()->newNetsSubscription('default')
        ->amount(9900)
        ->currency('DKK')
        ->intervalDays(30)
        ->description('Pro plan')
        ->checkoutUrl(route('billing.checkout'))
        ->termsUrl(route('terms'))
        ->endDate(now()->addYear())
        ->embeddedCheckout();

    return response()->json([
        'paymentId' => $checkout->paymentId(),
        'checkoutKey' => CashierNets::checkoutKey(),
        'checkoutJsUrl' => CashierNets::checkoutJsUrl(),
    ]);
});
```

The package is frontend-agnostic. Your application is responsible for rendering Nexi Checkout JS with the returned `paymentId`, checkout key, and checkout JS URL.

A minimal Blade-shaped example looks like this:

```blade
<div id="dibs-checkout"></div>

<script src="{{ $checkoutJsUrl }}"></script>
<script>
    const checkout = new Dibs.Checkout({
        checkoutKey: @json($checkoutKey),
        paymentId: @json($paymentId),
        containerId: 'dibs-checkout',
    });
</script>
```

Adapt the frontend code to your Blade, Livewire, Inertia, Vue, React, or other stack.

## Builder Methods

The subscription builder supports:

| Method | Purpose |
| --- | --- |
| `amount(int $amount)` | Required recurring amount in minor currency units. |
| `currency(string $currency)` | Currency code, default `DKK`. |
| `intervalDays(int $days)` | Minimum interval between recurring charges, default `30`. |
| `description(string $description)` | Checkout/order item description. |
| `reference(string $reference)` | Order reference sent to Nets. |
| `returnUrl(string $url)` | Required for hosted checkout. |
| `cancelUrl(string $url)` | Optional hosted checkout cancel URL. |
| `checkoutUrl(string $url)` | Required for embedded checkout. |
| `termsUrl(string $url)` | Optional terms URL. |
| `endDate(...)` | Required Nets subscription end date. |
| `chargeImmediately(bool $charge = true)` | Ask Nets to charge during checkout creation. |
| `metadata(array $metadata)` | Store local metadata on the subscription record. |

Nets requires an `endDate` when creating a subscription. The method accepts a `CarbonInterface`, `DateTimeInterface`, or date string.

## Local Subscription Records

After checkout is created, the package stores a pending local subscription in `nets_subscriptions`. Webhooks should move it to active and persist the Nets subscription identifier.

Hosted checkout callbacks can also finalize the package subscription by payment ID:

```php
$paymentId = (string) ($request->query('paymentid') ?? $request->query('paymentId') ?? '');

$subscription = $user->syncNetsSubscriptionFromPayment(
    paymentId: $paymentId,
    defaults: [
        'amount' => 9900,
        'currency' => 'DKK',
        'interval_days' => 30,
    ],
    type: 'default',
);
```

The lookup is scoped to the billable model and is idempotent for the same payment ID. The method returns the package `Subscription` after calling `syncFromNets()`, or throws `Udviklr\CashierNets\Exceptions\CheckoutFinalizationException` if Nets does not return a subscription ID.

Nets hosted checkout may return the payment identifier as lowercase `paymentid` in the return URL query string. Accept both `paymentid` and `paymentId` in application-owned callback routes.

The `payment.checkout.completed` webhook may arrive without a `subscriptionId`. In that case, the package leaves the local subscription pending until the callback finalizes it with `syncNetsSubscriptionFromPayment()` or the subscription ID is otherwise synced from Nets.

You can also manually sync provider identifiers from the payment details endpoint:

```php
$subscription = $user->netsSubscription('default');

$subscription?->syncFromNets();
```

## Access Checks

Use the `Billable` helpers to check subscription state:

```php
if ($user->subscribed()) {
    // The user has a valid default subscription.
}

if ($user->subscribed('team')) {
    // The user has a valid subscription of the "team" type.
}
```

A simple middleware can protect subscribed areas:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->subscribed()) {
            return redirect('/billing');
        }

        return $next($request);
    }
}
```
