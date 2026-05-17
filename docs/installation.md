# Installation

Install Laravel Cashier Nets with Composer:

```shell
composer require udviklr/laravel-cashier-nets
```

Publish the configuration and migrations:

```shell
php artisan vendor:publish --tag="cashier-nets-config"
php artisan vendor:publish --tag="cashier-nets-migrations"
php artisan migrate
```

The package also loads its bundled migrations automatically. Publishing the migrations is recommended when you want to review, customize, or order the database changes in your application.

## Version Support

Laravel Cashier Nets supports PHP `^8.1` and Laravel `10.x`, `11.x`, `12.x`, and `13.x`.

The test matrix follows Laravel's PHP requirements:

| Laravel | PHP |
| --- | --- |
| 10.x | 8.1-8.3 |
| 11.x | 8.2-8.4 |
| 12.x | 8.2-8.5 |
| 13.x | 8.3-8.5 |

## Billable Model

Add the `Billable` trait to the Eloquent model that owns subscriptions:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Udviklr\CashierNets\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

If your application bills another model, such as a team or organization, add the trait to that model instead.

## Database Tables

The package migrations create four tables:

| Table | Purpose |
| --- | --- |
| `nets_customers` | Local customer records tied to your billable models. |
| `nets_subscriptions` | Local subscription state, Nets identifiers, status, renewal date, amount, and metadata. |
| `nets_transactions` | Subscription charge attempts and webhook-driven payment outcomes. |
| `nets_webhook_events` | Received webhook payloads and processed timestamps for idempotency. |

Amounts are stored in minor currency units. For example, `9900` is `99.00 DKK`.

## Production Checklist

Before enabling live billing:

- Set live `NETS_SECRET_KEY` and `NETS_CHECKOUT_KEY`.
- Set `NETS_SANDBOX=false`.
- Set a high-entropy `NETS_WEBHOOK_SECRET` value.
- Ensure your public `APP_URL` is HTTPS and resolves to the application.
- Exclude the package webhook route from Laravel CSRF protection.
- Confirm Nexi can reach `/nets/webhook`, or your configured webhook path.
- Schedule `cashier-nets:charge-due` if the app should charge renewals.
- Run a sandbox checkout and webhook test before switching credentials.
